<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/invoices/{id}
 *
 * Politika mazání (NstyInvoice fork — rozšířená):
 *   - draft         → smí kdokoliv s rolí ≥ accountant (původní chování)
 *   - issued/sent/cancelled/paid → smí pouze admin
 *   - readonly      → nikdy
 *
 * Cascade chování (na DB úrovni — migrace 0012):
 *   Při smazání rodiče se automaticky smažou všichni potomci skrze
 *   parent_invoice_id (storno + dobropis + jejich items, work_reports).
 *   Bank pairing (matched_invoice_id) se nastaví na NULL.
 *
 * Stránka effects:
 *   1. PDF cache invalidace pro fakturu I všechny děti
 *   2. SQL DELETE s CASCADE (smaže items, work_reports, snapshoty…)
 *   3. StatsRecomputer pro klienta + projekt (revenue cache)
 *   4. ActivityLogger zapíše původní status / typ / varsymbol + ID smazaných dětí
 */
final class DeleteInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePdfRenderer $pdf,
        private readonly StatsRecomputer $stats,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $role   = (string) ($user['role'] ?? '');
        $status = (string) $existing['status'];

        // Role guard: ne-draft fakturu může smazat jen admin (force-delete účetního dokladu)
        if ($status !== 'draft' && $role !== 'admin') {
            return Json::error(
                $response,
                'admin_required',
                'Vystavenou/stornovanou fakturu může smazat jen admin.',
                403,
            );
        }
        if ($role === 'readonly') {
            return Json::error($response, 'forbidden', 'Read-only role nemůže mazat.', 403);
        }

        // Najdi všechny child doklady (storno, dobropis) — díky CASCADE se smažou s parentem,
        // ale chceme je zalogovat samostatně + invalidovat jejich PDF cache (DB to neudělá).
        $children = $this->db->pdo()->prepare(
            'SELECT id, invoice_type, varsymbol, status FROM invoices WHERE parent_invoice_id = ?'
        );
        $children->execute([$id]);
        $childRows = $children->fetchAll(\PDO::FETCH_ASSOC);

        // 1. Invalidate PDF cache (parent + děti) — soubory zůstanou na disku jinak orphan
        $this->pdf->invalidate($id);
        foreach ($childRows as $child) {
            $this->pdf->invalidate((int) $child['id']);
        }

        // Zachyt stats závislosti PŘED delete (po delete už nezjistíme client_id/project_id)
        $clientId  = isset($existing['client_id'])  ? (int) $existing['client_id']  : null;
        $projectId = isset($existing['project_id']) && $existing['project_id'] ? (int) $existing['project_id'] : null;

        // 2. Vlastní delete (CASCADE smaže items, work_reports, child invoices včetně jejich items)
        $this->repo->delete($id);

        // 3. Recompute revenue stats (po smazání issued/sent/paid se mění agregát)
        if ($clientId !== null) {
            $this->stats->recomputeForIds($clientId, $projectId);
        }

        // 4. Audit log — víc detailů pro force-delete než pro draft
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $eventName = ($status === 'draft') ? 'invoice.deleted' : 'invoice.force_deleted';
        $this->logger->log($eventName, $user['id'] ?? null, 'invoice', $id, [
            'varsymbol'           => $existing['varsymbol'] ?? null,
            'type'                => $existing['invoice_type'] ?? null,
            'status_before'       => $status,
            'total'               => $existing['total_with_vat'] ?? null,
            'currency'            => $existing['currency'] ?? null,
            'cascade_deleted_ids' => array_column($childRows, 'id'),
            'cascade_deleted'     => array_map(static fn ($c) => [
                'id'        => (int) $c['id'],
                'type'      => $c['invoice_type'],
                'varsymbol' => $c['varsymbol'],
                'status'    => $c['status'],
            ], $childRows),
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'ok'              => true,
            'cascade_deleted' => count($childRows),
        ]);
    }
}
