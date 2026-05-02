<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LogoutAction
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $user  = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        if ($token !== '') {
            $this->sessions->destroy($token);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            'auth.logout',
            isset($user['id']) ? (int) $user['id'] : null,
            'user',
            isset($user['id']) ? (int) $user['id'] : null,
            null,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        // Smaž cookie
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $cookieSecure = (bool) $this->config->get('session.cookie_secure', true);
        $cookie = sprintf(
            '%s=; HttpOnly; Path=/; Max-Age=0; SameSite=Lax%s',
            $cookieName,
            $cookieSecure ? '; Secure' : '',
        );

        return Json::ok($response, ['ok' => true])
            ->withHeader('Set-Cookie', $cookie);
    }
}
