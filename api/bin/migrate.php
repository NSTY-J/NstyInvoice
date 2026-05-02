<?php

declare(strict_types=1);

/**
 * Jednoduchý migrator: spustí SQL soubory z db/migrations/ v abecedním pořadí
 * a sleduje, co už proběhlo, v tabulce `migrations`.
 *
 * Použití:
 *   php api/bin/migrate.php          # spustí pending migrace
 *   php api/bin/migrate.php --status # vypíše stav bez aplikace
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$db      = (new Connection($config))->pdo();

$migrationsDir = $rootDir . '/db/migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(1);
}

// Zajisti tabulku migrations
$db->exec(
    'CREATE TABLE IF NOT EXISTS migrations ('
    . ' filename VARCHAR(190) PRIMARY KEY,'
    . ' applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
    . ' duration_ms INT UNSIGNED NOT NULL DEFAULT 0'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $db->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob($migrationsDir . '/*.sql');
sort($files, SORT_STRING);

$statusOnly = in_array('--status', $argv, true);

if ($statusOnly) {
    echo "Migration status:\n";
    foreach ($files as $file) {
        $name   = basename($file);
        $marker = isset($applied[$name]) ? '[x]' : '[ ]';
        echo "  {$marker} {$name}\n";
    }
    exit(0);
}

$pending = array_filter($files, fn (string $f) => !isset($applied[basename($f)]));

if (empty($pending)) {
    echo "Žádné nové migrace k aplikaci.\n";
    exit(0);
}

echo "Pending migrations: " . count($pending) . "\n";

foreach ($pending as $file) {
    $name = basename($file);
    echo "  → {$name} ... ";

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "READ FAILED\n");
        exit(1);
    }

    $start = microtime(true);
    try {
        foreach (splitSqlStatements($sql) as $stmt) {
            // Odstraň leading řádkové komentáře a prázdné řádky, abychom poznali prázdný statement
            $cleaned = preg_replace('/^(\s*--[^\n]*\n)+/', '', $stmt) ?? $stmt;
            $cleaned = trim($cleaned);
            if ($cleaned === '') {
                continue;
            }
            $db->exec($stmt);
        }
    } catch (\Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, '  Error: ' . $e->getMessage() . "\n");
        exit(1);
    }

    $durationMs = (int) ((microtime(true) - $start) * 1000);

    $stmt = $db->prepare('INSERT INTO migrations (filename, duration_ms) VALUES (?, ?)');
    $stmt->execute([$name, $durationMs]);

    echo "OK ({$durationMs} ms)\n";
}

echo "Hotovo.\n";

/**
 * Rozdělí SQL na jednotlivé statementy podle ';'.
 * Respektuje single-quoted stringy a komentáře `-- ...` a `/* ... *\/`.
 *
 * Pro bootstrap stačí — pro DELIMITER/triggery by bylo potřeba parser.
 */
function splitSqlStatements(string $sql): array
{
    $stmts = [];
    $current = '';
    $len = strlen($sql);
    $inSingle = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch  = substr($sql, $i, 1);
        $nxt = ($i + 1 < $len) ? substr($sql, $i + 1, 1) : '';

        if ($inLineComment) {
            $current .= $ch;
            if ($ch === "\n") $inLineComment = false;
            continue;
        }
        if ($inBlockComment) {
            $current .= $ch;
            if ($ch === '*' && $nxt === '/') {
                $current .= '/';
                $i++;
                $inBlockComment = false;
            }
            continue;
        }
        if ($inSingle) {
            $current .= $ch;
            if ($ch === '\\' && $nxt !== '') {
                $current .= $nxt;
                $i++;
                continue;
            }
            if ($ch === "'") $inSingle = false;
            continue;
        }

        if ($ch === '-' && $nxt === '-') {
            $current .= '--';
            $i++;
            $inLineComment = true;
            continue;
        }
        if ($ch === '/' && $nxt === '*') {
            $current .= '/*';
            $i++;
            $inBlockComment = true;
            continue;
        }
        if ($ch === "'") {
            $inSingle = true;
            $current .= $ch;
            continue;
        }
        if ($ch === ';') {
            if (trim($current) !== '') $stmts[] = $current;
            $current = '';
            continue;
        }
        $current .= $ch;
    }

    if (trim($current) !== '') $stmts[] = $current;

    return $stmts;
}
