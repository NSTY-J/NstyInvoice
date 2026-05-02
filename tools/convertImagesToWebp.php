<?php
/**
 * Konverze screenshotů v manual/img/ na WEBP.
 *
 * - Najde všechny *.png, *.jpg, *.jpeg v manual/img/ a vyrobí *.webp se stejným základem.
 * - Originály smaže (po úspěšném zápisu WEBP).
 * - Aktualizuje všechny .md soubory v manual/ — nahradí `(img/...{png,jpg,jpeg})` za `(img/...webp)`.
 * - Quality 80 (kompromis velikost / kvalita).
 *
 * Použití:
 *   php tools/convertImagesToWebp.php           # naostro
 *   php tools/convertImagesToWebp.php --dry     # jen ukáže, co by udělalo
 */

$dryRun = in_array('--dry', $argv ?? [], true);
$root = realpath(__DIR__ . '/..');
$imgDir = $root . '/manual/img';
$mdDir  = $root . '/manual';
$quality = 80;

if (!is_dir($imgDir)) {
    fwrite(STDERR, "Adresář neexistuje: $imgDir\n");
    exit(1);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "PHP nemá rozšíření 'gd'. Nainstaluj: apt-get install php-gd\n");
    exit(1);
}

// Sběr všech podporovaných obrázků (PNG + JPG + JPEG, case-insensitive)
$sources = glob($imgDir . '/*.{png,jpg,jpeg,PNG,JPG,JPEG}', GLOB_BRACE);
if (!$sources) {
    fwrite(STDERR, "V $imgDir nejsou žádné PNG ani JPG soubory.\n");
    exit(0);
}

$totalIn = 0;
$totalWebp = 0;
$converted = 0;

foreach ($sources as $src) {
    $base = pathinfo($src, PATHINFO_FILENAME);
    $ext  = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $webp = $imgDir . '/' . $base . '.webp';

    $sizeIn = filesize($src);
    $totalIn += $sizeIn;

    if ($dryRun) {
        echo "[dry] $base.$ext (" . number_format($sizeIn / 1024, 1) . " kB) → $base.webp\n";
        continue;
    }

    if ($ext === 'png') {
        $im = @imagecreatefrompng($src);
    } elseif ($ext === 'jpg' || $ext === 'jpeg') {
        $im = @imagecreatefromjpeg($src);
    } else {
        fwrite(STDERR, "Nepodporovaný formát: $src\n");
        continue;
    }
    if (!$im) {
        fwrite(STDERR, "Chyba čtení: $src\n");
        continue;
    }

    // Pro PNG zachovej alpha; pro JPG nemá smysl (nemá průhlednost).
    imagepalettetotruecolor($im);
    if ($ext === 'png') {
        imagealphablending($im, true);
        imagesavealpha($im, true);
    }

    if (!imagewebp($im, $webp, $quality)) {
        fwrite(STDERR, "Chyba zápisu: $webp\n");
        continue;
    }

    $sizeWebp = filesize($webp);
    $totalWebp += $sizeWebp;
    $saved = $sizeIn - $sizeWebp;
    $pct = $sizeIn > 0 ? round($saved * 100 / $sizeIn) : 0;
    echo "$base.$ext: " . number_format($sizeIn / 1024, 1) . " kB → "
       . number_format($sizeWebp / 1024, 1) . " kB (-{$pct}%)\n";

    unlink($src);
    $converted++;
}

if ($dryRun) {
    echo "(dry-run, žádné změny)\n";
    exit(0);
}

// Aktualizuj reference v MD souborech: (img/X.png|jpg|jpeg) → (img/X.webp)
$mdFiles = glob($mdDir . '/*.md');
$replaced = 0;
foreach ($mdFiles as $md) {
    $content = file_get_contents($md);
    $new = preg_replace_callback('~\((img/[^)\s]+)\.(png|jpe?g)\)~i', function ($m) use (&$replaced) {
        $replaced++;
        return '(' . $m[1] . '.webp)';
    }, $content);
    if ($new !== $content) {
        file_put_contents($md, $new);
        echo "  updated " . basename($md) . "\n";
    }
}

echo "\n";
echo "Konvertováno: $converted obrázků → WEBP\n";
echo "Celková velikost: " . number_format($totalIn / 1024, 1) . " kB → "
   . number_format($totalWebp / 1024, 1) . " kB ("
   . round(($totalIn - $totalWebp) * 100 / max(1, $totalIn)) . "% úspora)\n";
echo "Aktualizováno odkazů v MD: $replaced\n";
