<?php

declare(strict_types=1);

namespace MyInvoice\Action\AresVies;

use MyInvoice\Http\Json;
use MyInvoice\Service\Ares\ViesClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ViesLookupAction
{
    public function __construct(private readonly ViesClient $vies) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $vatId = strtoupper(trim((string) ($body['vat_id'] ?? '')));

        if (!preg_match('/^[A-Z]{2}\d{4,12}$/', $vatId)) {
            return Json::error($response, 'invalid_vat_id', 'DIČ musí mít prefix země a 4-12 číslic (např. CZ12345678).', 400);
        }

        $result = $this->vies->lookup($vatId);
        return Json::ok($response, $result);
    }
}
