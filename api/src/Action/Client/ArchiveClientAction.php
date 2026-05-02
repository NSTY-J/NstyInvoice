<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ArchiveClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $client = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $client)) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }

        $unarchive = str_ends_with($request->getUri()->getPath(), '/unarchive');
        if ($unarchive) {
            $this->repo->unarchive($id);
            $action = 'client.unarchived';
        } else {
            $this->repo->archive($id);
            $action = 'client.archived';
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'client', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true]);
    }
}
