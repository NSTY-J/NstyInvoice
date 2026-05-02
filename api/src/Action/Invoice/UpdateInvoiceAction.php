<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isForce = $request->getQueryParams()['force'] ?? null;
        $isAdmin = (($user['role'] ?? '') === 'admin');

        if ($existing['status'] !== 'draft') {
            // Pouze admin smí upravovat vystavenou fakturu, a to jen s explicit ?force=1.
            if (!$isAdmin || !$isForce) {
                return Json::error($response, 'not_editable', 'Vystavenou fakturu nelze editovat.', 409);
            }
            // Cancellation/credit_note jsou implicitně chráněné (auditní stopa)
            if (in_array($existing['invoice_type'], ['cancellation'], true)) {
                return Json::error($response, 'not_editable', 'Storno doklad nelze editovat.', 409);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        // Type a parent_invoice_id se nemění při update
        $body['invoice_type']      = $existing['invoice_type'];
        $body['parent_invoice_id'] = $existing['parent_invoice_id'];
        try {
            $body = $this->defaults->resolve($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $errors = InvoiceValidation::invoice($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        $this->repo->updateDraft($id, $body);
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);
        // Force update vystavené faktury → revenue cache musí přijmout nové total/currency
        $this->stats->recomputeForInvoiceId($id);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($existing['status'] !== 'draft') ? 'invoice.force_updated' : 'invoice.updated';
        $this->logger->log($action, $user['id'] ?? null, 'invoice', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
