<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/invoices/{id}/work-report
 * body: { project_id: int, title: string, items: [{description, hours, rate, order_index?}] }
 */
final class SaveWorkReportAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly WorkReportRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePdfRenderer $pdf,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $invoiceId = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($invoiceId);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isAdmin = (($user['role'] ?? '') === 'admin');
        $isForce = !empty($request->getQueryParams()['force']);

        if ($invoice['status'] !== 'draft' && !($isAdmin && $isForce)) {
            return Json::error($response, 'not_editable', 'Výkaz lze upravit pouze v draftu (admin: ?force=1).', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $projectId = (int) ($body['project_id'] ?? 0);
        $title = trim((string) ($body['title'] ?? ''));
        $items = (array) ($body['items'] ?? []);

        if ($projectId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí project_id.', 400);
        }
        if ($title === '') {
            return Json::error($response, 'validation_failed', 'Chybí title.', 400);
        }
        // Validace items
        foreach ($items as $idx => $it) {
            if (trim((string) ($it['description'] ?? '')) === '') {
                return Json::error($response, 'validation_failed', "items[$idx].description je povinný.", 400);
            }
            if ((float) ($it['hours'] ?? 0) <= 0) {
                return Json::error($response, 'validation_failed', "items[$idx].hours musí být > 0.", 400);
            }
            if ((float) ($it['rate'] ?? 0) < 0) {
                return Json::error($response, 'validation_failed', "items[$idx].rate musí být >= 0.", 400);
            }
            $wd = trim((string) ($it['work_date'] ?? ''));
            if ($wd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $wd)) {
                return Json::error($response, 'validation_failed', "items[$idx].work_date musí být ve formátu YYYY-MM-DD.", 400);
            }
        }

        $id = $this->repo->save($invoiceId, $projectId, $title, $items);
        $wr = $this->repo->findByInvoice($invoiceId);
        $this->pdf->invalidate($invoiceId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($invoice['status'] !== 'draft') ? 'work_report.force_saved' : 'work_report.saved';
        $this->logger->log($action, $user['id'] ?? null, 'work_report', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $wr);
    }
}
