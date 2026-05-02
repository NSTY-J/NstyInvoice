<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ProjectRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetProjectAction
{
    public function __construct(
        private readonly ProjectRepository $repo,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $project = $this->repo->find($id);
        if ($project === null || (int) ($project['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE project_id = ?');
        $stmt->execute([$id]);
        $project['invoices_count'] = (int) $stmt->fetchColumn();
        return Json::ok($response, $project);
    }
}
