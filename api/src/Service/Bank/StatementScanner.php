<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Skenuje adresář (rekurzivně) na *.gpc / *.txt soubory a importuje je
 * přes StatementImporter (SHA256 dedupe). Vrátí summary.
 */
final class StatementScanner
{
    public function __construct(private readonly StatementImporter $importer) {}

    /**
     * @return array{scanned:int, imported:int, duplicate:int, errors:int, files:list<array{file:string,result:array}>}
     */
    public function scan(string $rootDir): array
    {
        $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');
        $scanned = 0; $imported = 0; $duplicate = 0; $errors = 0;
        $files = [];

        if (!is_dir($rootDir)) {
            return ['scanned' => 0, 'imported' => 0, 'duplicate' => 0, 'errors' => 0, 'files' => []];
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $entry) {
            if (!$entry->isFile()) continue;
            $ext = strtolower($entry->getExtension());
            if (!in_array($ext, ['gpc', 'txt'], true)) continue;

            $scanned++;
            $path = $entry->getPathname();
            $name = $entry->getFilename();
            try {
                $content = (string) file_get_contents($path);
                $r = $this->importer->import($content, $name, null);
                if ($r['duplicate']) $duplicate++;
                else $imported++;
                $files[] = ['file' => $name, 'result' => $r];
            } catch (\Throwable $e) {
                $errors++;
                $files[] = ['file' => $name, 'result' => ['error' => $e->getMessage()]];
            }
        }

        return [
            'scanned'   => $scanned,
            'imported'  => $imported,
            'duplicate' => $duplicate,
            'errors'    => $errors,
            'files'     => $files,
        ];
    }
}
