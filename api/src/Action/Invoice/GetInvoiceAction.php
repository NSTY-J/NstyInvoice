<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetInvoiceAction
{
    public function __construct(private readonly InvoiceRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $invoice = $this->repo->find($id);
        if ($invoice === null || (int) ($invoice['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        return Json::ok($response, $invoice);
    }
}
