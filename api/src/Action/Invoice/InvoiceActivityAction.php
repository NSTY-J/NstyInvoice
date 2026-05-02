<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/activity
 * Vrátí všechny activity_log záznamy pro danou fakturu (auth, ne admin-only).
 * Konvertuje IP z packed binary na string.
 */
final class InvoiceActivityAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $sql = "SELECT al.id, al.user_id, u.email AS user_email, u.name AS user_name,
                       al.action, al.payload, al.ip, al.created_at
                  FROM activity_log al
             LEFT JOIN users u ON u.id = al.user_id
                 WHERE al.entity_type = 'invoice' AND al.entity_id = ?
              ORDER BY al.id DESC
                 LIMIT 200";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            if ($r['payload'] !== null) {
                $r['payload'] = json_decode((string) $r['payload'], true);
            }
            if ($r['ip'] !== null && $r['ip'] !== '') {
                $r['ip'] = @inet_ntop($r['ip']) ?: null;
            } else {
                $r['ip'] = null;
            }
            $r['id'] = (int) $r['id'];
            $r['user_id'] = $r['user_id'] !== null ? (int) $r['user_id'] : null;
        }

        return Json::ok($response, $rows);
    }
}
