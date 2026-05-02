<?php

declare(strict_types=1);

/**
 * Initial backfill cache tabulek `project_revenue_cache` + `client_revenue_cache`.
 * Idempotentní — lze pouštět opakovaně. Mimo to invoice actions volají recompute
 * automaticky při create/issue/cancel/markPaid/update.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();

$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$stats = $container->get(\MyInvoice\Service\Stats\StatsRecomputer::class);

$projects = $pdo->query('SELECT id FROM projects')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
$clients  = $pdo->query('SELECT id FROM clients')->fetchAll(\PDO::FETCH_COLUMN) ?: [];

echo "Recomputing " . count($projects) . " projects + " . count($clients) . " clients...\n";

foreach ($projects as $id) {
    $stats->recomputeProject((int) $id);
    echo ".";
}
echo "\n";
foreach ($clients as $id) {
    $stats->recomputeClient((int) $id);
    echo ".";
}
echo "\n\nHotovo.\n";
