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

final class DeleteInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($existing['status'] !== 'draft') {
            return Json::error($response, 'not_deletable', 'Lze smazat jen draft fakturu (vystavenou jen storno/dobropis).', 409);
        }

        $this->repo->delete($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.deleted', $user['id'] ?? null, 'invoice', $id, [
            'varsymbol' => $existing['varsymbol'],
            'type'      => $existing['invoice_type'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true]);
    }
}
