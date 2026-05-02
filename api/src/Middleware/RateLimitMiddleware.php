<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Sliding-window rate limiter dle cfg.rate_limits.
 *
 *   login_per_min_per_ip      → 60s window, key = "rl:login:ip:{ip/24}"
 *   forgot_per_hour_per_email → 3600s window, key = "rl:forgot:email:{sha1}"
 *   ares_per_min_per_user     → 60s window, key = "rl:ares:user:{id}"
 *   setup_per_hour_per_ip     → 3600s window, key = "rl:setup:ip:{ip}"
 *   mutation_per_min_per_user → 60s window, key = "rl:mut:user:{id}" (POST/PUT/PATCH/DELETE)
 *   read_per_min_per_user     → 60s window, key = "rl:read:user:{id}" (GET)
 *
 * Při překročení vrátí 429 Too Many Requests s Retry-After.
 *
 * Vyžaduje Redis. Bez Redis je rate limit no-op (BruteForceGuard pokrývá login).
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly RedisFactory $redis,
        private readonly ResponseFactory $responseFactory,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $r = $this->redis->client();
        if ($r === null) {
            return $handler->handle($request); // bez Redis no-op
        }

        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());
        $ip = $this->ipMatcher->clientIp(
            $request->getServerParams(),
            (array) $this->config->get('ip_allowlist.trusted_proxies', []),
            (string) $this->config->get('ip_allowlist.header', 'X-Forwarded-For'),
        );

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : 0;

        // Per-route limity — vrací [key, limit, window]
        $rule = $this->ruleFor($path, $method, $ip, $userId, $request);
        if ($rule === null) {
            return $handler->handle($request);
        }

        [$key, $limit, $window] = $rule;
        $count = (int) ($r->get($key) ?? 0);

        if ($count >= $limit) {
            $ttl = (int) $r->ttl($key);
            $response = $this->responseFactory->createResponse(429);
            $response = $response->withHeader('Retry-After', (string) max(1, $ttl));
            return Json::error($response, 'rate_limited', 'Příliš mnoho pokusů. Zkus to později.', 429);
        }

        // Increment + set expiry (idempotentně)
        $r->incr($key);
        $r->expire($key, $window);

        return $handler->handle($request);
    }

    /**
     * @return array{0:string,1:int,2:int}|null  [redisKey, limit, windowSeconds]
     */
    private function ruleFor(string $path, string $method, string $ip, int $userId, Request $request): ?array
    {
        $rl = (array) $this->config->get('rate_limits', []);

        // Login — všichni
        if ($path === '/api/auth/login' && $method === 'POST') {
            return ['rl:login:ip:' . $this->ipBucket($ip), (int) ($rl['login_per_min_per_ip'] ?? 10), 60];
        }

        // Forgot — per email
        if ($path === '/api/auth/forgot' && $method === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            if ($email !== '') {
                return ['rl:forgot:email:' . sha1($email), (int) ($rl['forgot_per_hour_per_email'] ?? 3), 3600];
            }
        }

        // Setup
        if ($path === '/api/auth/setup' && $method === 'POST') {
            return ['rl:setup:ip:' . $this->ipBucket($ip), (int) ($rl['setup_per_hour_per_ip'] ?? 5), 3600];
        }

        // Setup ARES lookup (public během setup okna) — chrání proti DoS na ARES
        if ($path === '/api/auth/setup-ares-lookup' && $method === 'POST') {
            return ['rl:setup-ares:ip:' . $this->ipBucket($ip), 10, 60]; // 10/min/IP
        }

        // ARES / VIES lookups (per user) — chrání 24h cache před zaplněním
        if (in_array($path, ['/api/clients/lookup-ares', '/api/clients/lookup-vies'], true) && $userId > 0) {
            return ['rl:ares:user:' . $userId, (int) ($rl['ares_per_min_per_user'] ?? 30), 60];
        }

        // Generic per-user mutation/read limit (jen pro přihlášené, mimo public)
        if ($userId > 0 && !str_starts_with($path, '/api/auth/')) {
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return ['rl:mut:user:' . $userId, (int) ($rl['mutation_per_min_per_user'] ?? 60), 60];
            }
            if ($method === 'GET') {
                return ['rl:read:user:' . $userId, (int) ($rl['read_per_min_per_user'] ?? 300), 60];
            }
        }

        return null;
    }

    private function ipBucket(string $ip): string
    {
        $packed = inet_pton($ip);
        if ($packed === false) return sha1($ip);
        if (strlen($packed) === 4) return bin2hex(substr($packed, 0, 3)); // /24
        return bin2hex(substr($packed, 0, 8)); // /64
    }
}
