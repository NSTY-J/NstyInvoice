<?php

declare(strict_types=1);

namespace MyInvoice\Action\System;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedisProbe $redis,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        return Json::ok($response, [
            'status'  => 'ok',
            'version' => '0.1.0',
            'env'     => $this->config->get('app.env'),
            'db'      => $this->db->ping(),
            'redis'   => $this->redis->isAvailable(),
            'time'    => date(\DateTimeInterface::ATOM),
        ]);
    }
}
