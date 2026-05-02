<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Bootstrap;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD pro email šablony:
 *   GET    /api/admin/email-templates                   — list (DB + file defaults)
 *   GET    /api/admin/email-templates/{code}/{locale}   — detail (DB nebo defaultní soubor)
 *   PUT    /api/admin/email-templates/{code}/{locale}   — upsert override
 *   DELETE /api/admin/email-templates/{code}/{locale}   — smaž override (vrátí na default)
 */
final class EmailTemplateAction
{
    /**
     * Známé kódy šablon — fix list, ne dynamický.
     * Při přidání nového typu emailu rozšířit zde a v api/templates/email/.
     */
    private const KNOWN = ['invoice_send', 'invoice_reminder', 'password_reset'];
    private const LOCALES = ['cs', 'en'];

    public function __construct(
        private readonly EmailTemplateRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;

        $byKey = [];
        foreach ($this->repo->listAll() as $row) {
            $byKey[$row['code'] . '.' . $row['locale']] = [
                'has_override' => true,
                'updated_at'   => $row['updated_at'],
            ];
        }

        $rows = [];
        foreach (self::KNOWN as $code) {
            foreach (self::LOCALES as $locale) {
                $key = "$code.$locale";
                $rows[] = [
                    'code'         => $code,
                    'locale'       => $locale,
                    'has_override' => $byKey[$key]['has_override'] ?? false,
                    'updated_at'   => $byKey[$key]['updated_at'] ?? null,
                ];
            }
        }
        return Json::ok($response, ['data' => $rows]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $code = (string) ($args['code'] ?? '');
        $locale = (string) ($args['locale'] ?? '');
        if (!in_array($code, self::KNOWN, true) || !in_array($locale, self::LOCALES, true)) {
            return Json::error($response, 'not_found', 'Šablona neexistuje.', 404);
        }

        $tpl = $this->repo->find($code, $locale);
        $defaults = $this->loadDefaults($code, $locale);

        return Json::ok($response, [
            'code'      => $code,
            'locale'    => $locale,
            'subject'   => $tpl['subject']   ?? $defaults['subject'],
            'body_html' => $tpl['body_html'] ?? $defaults['body_html'],
            'body_text' => $tpl['body_text'] ?? $defaults['body_text'],
            'has_override' => $tpl !== null,
            'updated_at'   => $tpl['updated_at'] ?? null,
            'defaults'  => $defaults, // pro „Reset na default" tlačítko v UI
        ]);
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $code = (string) ($args['code'] ?? '');
        $locale = (string) ($args['locale'] ?? '');
        if (!in_array($code, self::KNOWN, true) || !in_array($locale, self::LOCALES, true)) {
            return Json::error($response, 'not_found', 'Šablona neexistuje.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $subject  = trim((string) ($body['subject']   ?? ''));
        $bodyHtml = (string) ($body['body_html'] ?? '');
        $bodyText = (string) ($body['body_text'] ?? '');

        if ($subject === '')  return Json::error($response, 'validation_failed', 'Chybí subject.', 400);
        if ($bodyHtml === '') return Json::error($response, 'validation_failed', 'Chybí body_html.', 400);
        if ($bodyText === '') return Json::error($response, 'validation_failed', 'Chybí body_text.', 400);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->repo->save($code, $locale, $subject, $bodyHtml, $bodyText, isset($user['id']) ? (int) $user['id'] : null);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('email_template.saved', $user['id'] ?? null, 'email_template', null, [
            'code' => $code, 'locale' => $locale,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['saved' => true]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $code = (string) ($args['code'] ?? '');
        $locale = (string) ($args['locale'] ?? '');
        $this->repo->delete($code, $locale);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('email_template.reset', $user['id'] ?? null, 'email_template', null, [
            'code' => $code, 'locale' => $locale,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    private function loadDefaults(string $code, string $locale): array
    {
        $dir = Bootstrap::rootDir() . '/api/templates/email';
        $html = @file_get_contents("$dir/{$code}.{$locale}.html.twig") ?: '';
        $text = @file_get_contents("$dir/{$code}.{$locale}.txt.twig") ?: '';
        return [
            'subject'   => $this->defaultSubject($code, $locale),
            'body_html' => $html,
            'body_text' => $text,
        ];
    }

    private function defaultSubject(string $code, string $locale): string
    {
        $cs = [
            'invoice_send'     => 'Faktura {{ invoice.varsymbol }}',
            'invoice_reminder' => 'Upomínka — faktura {{ invoice.varsymbol }} ({{ days_overdue }} dní po splatnosti)',
            'password_reset'   => 'Obnova hesla',
        ];
        $en = [
            'invoice_send'     => 'Invoice {{ invoice.varsymbol }}',
            'invoice_reminder' => 'Reminder — invoice {{ invoice.varsymbol }} ({{ days_overdue }} days overdue)',
            'password_reset'   => 'Password reset',
        ];
        return ($locale === 'en' ? $en : $cs)[$code] ?? '';
    }

    private function guard(Request $request, Response $response, ?Response &$err): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }
}
