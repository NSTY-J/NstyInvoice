<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stats\StatsRecomputer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přechod draft → issued:
 *  1. Vygeneruje varsymbol (atomicky)
 *  2. Zapíše snapshots (client, supplier, bank)
 *  3. Status = issued
 *
 * Po issued už faktura nelze editovat — jen storno/dobropis/mark-paid.
 */
final class IssueInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['status'] !== 'draft') {
            return Json::error($response, 'not_draft', 'Lze vystavit jen draft fakturu.', 409);
        }
        if (count($invoice['items']) === 0) {
            return Json::error($response, 'no_items', 'Faktura musí obsahovat alespoň jednu položku.', 422);
        }
        if ($invoice['invoice_type'] === 'cancellation') {
            return Json::error($response, 'invalid_type', 'Storno nedostává varsymbol.', 422);
        }

        $issueDate = new \DateTimeImmutable($invoice['issue_date']);

        $supplierId = (int) $invoice['supplier_id'];

        try {
            $varsymbol = $this->varsymbol->next($supplierId, $invoice['invoice_type'], $issueDate);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'varsymbol_failed', $e->getMessage(), 500);
        }

        try {
            $snapshots = $this->snapshots->build((int) $invoice['client_id'], (int) $invoice['currency_id'], $supplierId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'snapshot_failed', $e->getMessage(), 500);
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE invoices SET
                varsymbol         = ?,
                client_snapshot   = ?,
                supplier_snapshot = ?,
                bank_snapshot     = ?,
                status            = "issued"
             WHERE id = ? AND status = "draft"'
        );
        $stmt->execute([
            $varsymbol,
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $id,
        ]);

        if ($stmt->rowCount() === 0) {
            return Json::error($response, 'race_condition', 'Faktura byla mezitím změněna.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.issued', $user['id'] ?? null, 'invoice', $id, [
            'varsymbol' => $varsymbol,
            'type'      => $invoice['invoice_type'],
            'total'     => $invoice['total_with_vat'],
            'currency'  => $invoice['currency'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $this->stats->recomputeForInvoiceId($id);

        return Json::ok($response, $this->repo->find($id));
    }
}
