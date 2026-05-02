<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Pokud je tabulka `users` prázdná (čerstvá instalace), povolí jen:
 *   GET  /api/health
 *   GET  /api/auth/setup-status
 *   POST /api/auth/setup
 * Vše ostatní vrací 423 Locked s `setup_required`.
 */
final class FirstRunLockMiddleware implements MiddlewareInterface
{
    private const ALLOWED_PATHS = [
        'GET /api/health',
        'GET /api/auth/setup-status',
        'POST /api/auth/setup',
        'POST /api/auth/setup-ares-lookup',
        // setup-sample je záměrně ve PUBLIC_PATHS ale NE v ALLOWED_PATHS:
        // volá se až PO úspěšném /setup, kdy needsSetup() vrací false (admin existuje).
    ];

    private ?bool $needsSetupCache = null;

    public function __construct(
        private readonly Connection $db,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if (!$this->needsSetup()) {
            return $handler->handle($request);
        }

        $key = strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath();
        if (in_array($key, self::ALLOWED_PATHS, true)) {
            return $handler->handle($request);
        }

        $response = $this->responseFactory->createResponse(423);
        return Json::error(
            $response,
            'setup_required',
            'Aplikace ještě není inicializovaná. Otevřete /setup pro vytvoření admin účtu.',
            423,
        );
    }

    public function needsSetup(): bool
    {
        if ($this->needsSetupCache !== null) {
            return $this->needsSetupCache;
        }

        try {
            $count = (int) $this->db->pdo()
                ->query('SELECT COUNT(*) FROM users WHERE is_active = 1')
                ->fetchColumn();
            $this->needsSetupCache = $count === 0;
        } catch (\Throwable) {
            // Pokud DB nejde, raději necháme middleware projít a chyba se ukáže výš.
            $this->needsSetupCache = false;
        }

        return $this->needsSetupCache;
    }
}
