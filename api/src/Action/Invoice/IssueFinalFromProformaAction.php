<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Vystaví finální daňový doklad k zaplacené proformě.
 * Vytvoří DRAFT typu `invoice` s:
 *   - parent_invoice_id = id proformy
 *   - kopie všech položek z proformy
 *   - advance_paid_amount = (custom nebo proforma.total_with_vat)
 *   - amount_to_pay = total - advance (typicky 0)
 *
 * User pak otevře editor, zkontroluje a zavolá /issue.
 */
final class IssueFinalFromProformaAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $proformaId = (int) ($args['id'] ?? 0);
        $proforma = $this->repo->find($proformaId);
        if (!SupplierGuard::owns($request, $proforma)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($proforma['invoice_type'] !== 'proforma') {
            return Json::error($response, 'not_proforma', 'Lze pouze ze zálohové faktury (proforma).', 409);
        }
        if ($proforma['status'] !== 'paid') {
            return Json::error($response, 'not_paid', 'Proforma musí být označená jako zaplacená.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $taxDate = (string) ($body['tax_date'] ?? date('Y-m-d'));
        $dueDate = (string) ($body['due_date'] ?? date('Y-m-d'));
        $advance = isset($body['advance_paid_amount']) && $body['advance_paid_amount'] !== null && $body['advance_paid_amount'] !== ''
            ? (float) $body['advance_paid_amount']
            : (float) $proforma['total_with_vat'];

        if ($advance < 0) {
            return Json::error($response, 'invalid_advance', 'Záloha nesmí být záporná.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, parent_invoice_id, client_id, project_id, supplier_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, language,
                    note_above_items, advance_paid_amount, status, created_by)
                 VALUES ("invoice", ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $proformaId,
                $proforma['client_id'],
                $proforma['project_id'],
                (int) $proforma['supplier_id'],
                $taxDate,
                $dueDate,
                (int) $proforma['currency_id'],
                $proforma['reverse_charge'] ? 1 : 0,
                $proforma['language'],
                "Daňový doklad k zálohové faktuře {$proforma['varsymbol']}",
                $advance,
                $userId,
            ]);
            $finalId = (int) $pdo->lastInsertId();

            // Zkopíruj všechny položky z proformy
            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)'
            );
            foreach ($proforma['items'] as $item) {
                $itemStmt->execute([
                    $finalId,
                    $item['description'],
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $item['order_index'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'create_failed', $e->getMessage(), 500);
        }

        $this->calc->recompute($finalId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('proforma.final_issued', $userId, 'invoice', $proformaId, [
            'final_invoice_id'    => $finalId,
            'advance_paid_amount' => $advance,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'final_invoice_id' => $finalId,
            'edit_url'         => "/invoices/$finalId/edit",
            'invoice'          => $this->repo->find($finalId),
        ], 201);
    }
}
