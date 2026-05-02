<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Config;

/**
 * Konfigurace aplikace — načítá `cfg.php` z rootu repa,
 * volitelně mergne `cfg.local.php` přes `array_replace_recursive`.
 *
 * Přístup přes dot notation: $cfg->get('db.host'), $cfg->get('smtp.from_email').
 */
final class Config
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(string $rootDir): self
    {
        $basePath  = $rootDir . DIRECTORY_SEPARATOR . 'cfg.php';
        $localPath = $rootDir . DIRECTORY_SEPARATOR . 'cfg.local.php';

        if (!is_file($basePath)) {
            throw new \RuntimeException("cfg.php nenalezen v {$rootDir}");
        }

        $base  = require $basePath;
        $local = is_file($localPath) ? require $localPath : [];

        if (!is_array($base) || !is_array($local)) {
            throw new \RuntimeException('cfg.php (a cfg.local.php) musí vracet pole');
        }

        return new self(array_replace_recursive($base, $local));
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $value    = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->data;
    }
}
