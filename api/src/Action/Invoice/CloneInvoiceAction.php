<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/clone
 * Body (volitelně):
 *   { "increment_month_in_descriptions": true, "issue_date": "YYYY-MM-DD" }
 *
 * Vrací: { draft_id: int }
 */
final class CloneInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly BulkReissueAction $bulk,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $incrementMonth = (bool) ($body['increment_month_in_descriptions'] ?? false);
        $issueDate = !empty($body['issue_date']) ? (string) $body['issue_date'] : date('Y-m-d');

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        try {
            $newId = $this->bulk->cloneOne($id, $issueDate, $incrementMonth, $userId);
        } catch (\Throwable $e) {
            return Json::error($response, 'clone_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.cloned', $userId, 'invoice', $id, [
            'new_draft_id' => $newId, 'increment_month' => $incrementMonth,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['draft_id' => $newId], 201);
    }
}
