<?php

declare(strict_types=1);

/**
 * Denní DB backup — mariadb-dump → ZIP do storage/backup/.
 * Retention: 30 denních + 12 měsíčních (1. v měsíci se zachová déle).
 *
 * Vyžaduje v PATH: mariadb-dump (případně mysqldump) a PHP ext-zip.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);

$dbHost = (string) $config->get('db.host');
$dbName = (string) $config->get('db.name');
$dbUser = (string) $config->get('db.user');
$dbPass = (string) $config->get('db.pass');
$dbPort = (int)    $config->get('db.port', 3306);

$backupDir = $rootDir . '/storage/backup';
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP ext-zip není nainstalována.\n");
    exit(1);
}

$date    = date('Y-m-d');
$file    = "$backupDir/$dbName-$date.zip";
$sqlTmp  = "$backupDir/.$dbName-$date.sql";
$sqlName = "$dbName-$date.sql";

// Test dostupnosti dump nástroje:
//   1) explicitní cesta z configu (db.dump_tool)
//   2) PATH (mariadb-dump → mysqldump)
//   3) běžné instalační lokace na Windows
$inPath = static function (string $bin): bool {
    $out = []; $rc = 1;
    @exec(sprintf('%s --version 2>&1', escapeshellarg($bin)), $out, $rc);
    return $rc === 0;
};
$tool = (string) $config->get('db.dump_tool', '');
if ($tool !== '' && !@is_executable($tool)) $tool = '';
if ($tool === '') {
    if      ($inPath('mariadb-dump')) $tool = 'mariadb-dump';
    else if ($inPath('mysqldump'))    $tool = 'mysqldump';
}
if ($tool === '' && stripos(PHP_OS, 'WIN') === 0) {
    $candidates = array_merge(
        glob('C:\\Program Files\\MariaDB*\\bin\\mariadb-dump.exe') ?: [],
        glob('C:\\Program Files\\MariaDB*\\bin\\mysqldump.exe')    ?: [],
        glob('C:\\Program Files\\MySQL\\*\\bin\\mysqldump.exe')    ?: [],
        glob('C:\\inetpub\\MariaDB\\bin\\mariadb-dump.exe')        ?: [],
        glob('C:\\inetpub\\MariaDB\\bin\\mysqldump.exe')           ?: [],
        glob('C:\\xampp\\mysql\\bin\\mysqldump.exe')               ?: [],
        glob('C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe')     ?: []
    );
    $tool = $candidates[0] ?? '';
}
if ($tool === '') {
    fwrite(STDERR, "mariadb-dump ani mysqldump není v PATH (ani v běžných instalačních cestách). Nastav db.dump_tool v cfg.php.\n");
    exit(1);
}

// Heslo + connection params přes --defaults-extra-file (ne v cmdline, ne přes env).
// Win-fix: 'localhost' se občas nezresolvuje (mariadb-dump 2005 / 11003) → 127.0.0.1.
$dumpHost = ($dbHost === 'localhost') ? '127.0.0.1' : $dbHost;
$cnf = tempnam(sys_get_temp_dir(), 'myinv-dump-') ?: ($rootDir . '/storage/backup/.dump.cnf');
file_put_contents($cnf, sprintf(
    "[client]\nhost=%s\nport=%d\nuser=%s\npassword=\"%s\"\n",
    $dumpHost,
    $dbPort,
    $dbUser,
    str_replace(['\\', '"'], ['\\\\', '\\"'], $dbPass)
));
@chmod($cnf, 0600);

$errFile = $rootDir . '/storage/backup/.last-error';
$cmd = sprintf(
    '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers %s 2>%s',
    escapeshellcmd($tool),
    escapeshellarg($cnf),
    escapeshellarg($dbName),
    escapeshellarg($errFile)
);

// 1) dump do dočasného .sql souboru
$out = fopen($sqlTmp, 'wb');
if (!$out) {
    @unlink($cnf);
    fwrite(STDERR, "Cannot open temp SQL for writing: $sqlTmp\n");
    exit(1);
}
$proc = proc_open(
    $cmd,
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $rootDir
);
if (!is_resource($proc)) {
    fclose($out);
    @unlink($cnf); @unlink($sqlTmp);
    fwrite(STDERR, "Cannot start backup process.\n");
    exit(1);
}
fclose($pipes[0]);
fclose($pipes[2]);
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 65536);
    if ($chunk === false || $chunk === '') break;
    fwrite($out, $chunk);
}
fclose($pipes[1]);
$rc = proc_close($proc);
fclose($out);
@unlink($cnf);

if ($rc !== 0 || !is_file($sqlTmp) || filesize($sqlTmp) < 100) {
    $err = is_file($errFile) ? trim((string) file_get_contents($errFile)) : '';
    fwrite(STDERR, "Backup selhal (rc=$rc)" . ($err !== '' ? ": $err" : '') . "\n");
    @unlink($sqlTmp);
    exit(1);
}

// 2) zabalit do ZIP a dočasný .sql smazat
@unlink($file);
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($sqlTmp);
    fwrite(STDERR, "Cannot create ZIP: $file\n");
    exit(1);
}
$zip->addFile($sqlTmp, $sqlName);
if (defined('ZipArchive::CM_DEFLATE')) {
    $zip->setCompressionName($sqlName, ZipArchive::CM_DEFLATE, 9);
}
if (!$zip->close()) {
    @unlink($sqlTmp); @unlink($file);
    fwrite(STDERR, "ZIP close failed.\n");
    exit(1);
}
@unlink($sqlTmp);

if (!is_file($file) || filesize($file) < 100) {
    fwrite(STDERR, "ZIP backup is empty.\n");
    @unlink($file);
    exit(1);
}

$size = round(filesize($file) / 1024, 1);
echo "[" . date('Y-m-d H:i:s') . "] backup: " . basename($file) . " ({$size} KB)\n";

// Retention: smaž denní starší 30 dní (kromě 1. v měsíci, ty drž 365 dní)
// Bere v potaz i staré .sql.gz formáty z dřívějška.
$files = array_merge(
    glob($backupDir . '/*.zip')    ?: [],
    glob($backupDir . '/*.sql.gz') ?: []
);
$now = time();
foreach ($files as $f) {
    if (!preg_match('/-(\d{4}-\d{2}-\d{2})\.(zip|sql\.gz)$/', $f, $m)) continue;
    $age = $now - strtotime($m[1]);
    $isMonthly = str_ends_with($m[1], '-01');
    $maxAge = $isMonthly ? 365 * 86400 : 30 * 86400;
    if ($age > $maxAge) {
        @unlink($f);
        echo "  - retention: smazáno " . basename($f) . "\n";
    }
}
