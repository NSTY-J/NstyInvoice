<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderuje fakturu jako PDF.
 *
 *   1. Načte fakturu (full + items + snapshots)
 *   2. Vyrendrované Twig šablonu invoice.twig
 *   3. Vygeneruje QR (pokud má varsymbol + amount + bank)
 *   4. mPDF z HTML
 *   5. Cache do storage/invoices/YYYY-MM/Faktura-YY-MM-NNN.pdf
 */
final class InvoicePdfRenderer
{
    private ?Environment $twig = null;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly QrPaymentGenerator $qr,
        private readonly WorkReportRepository $workReports,
    ) {}

    /**
     * Vyrendrované PDF do souboru a vrátí cestu.
     *
     * @return string  absolutní cesta k vygenerovanému PDF
     */
    public function render(int $invoiceId, bool $forceRegenerate = false): string
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Faktura #{$invoiceId} nenalezena");
        }

        $cachedPath = $this->cachePath($invoice);

        // Cache je validní jen když je novější než šablona, CSS a kód renderu
        $tplMtime = max(
            @filemtime(Bootstrap::rootDir() . '/styles/invoice.css') ?: 0,
            @filemtime(Bootstrap::rootDir() . '/api/templates/invoice/invoice.twig') ?: 0,
            @filemtime(__FILE__) ?: 0,
        );
        $isFresh = static fn (string $p): bool =>
            is_file($p) && (@filemtime($p) ?: 0) >= $tplMtime;

        if (!$forceRegenerate && $invoice['pdf_path'] && $isFresh($invoice['pdf_path'])) {
            return $invoice['pdf_path'];
        }
        if (!$forceRegenerate && $isFresh($cachedPath)) {
            $this->updatePdfPath($invoiceId, $cachedPath);
            return $cachedPath;
        }

        $rendered = $this->renderHtmlAndCss($invoice);

        $rootDir = Bootstrap::rootDir();
        $tmpDir = $rootDir . '/storage/cache/mpdf';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'margin_top'        => 15,
            'margin_bottom'     => 18,
            'margin_left'       => 15,
            'margin_right'      => 15,
            'tempDir'           => $tmpDir,
            'default_font'      => 'dejavusans',
            'autoPageBreak'     => true,
        ]);
        // PDF metadata — bez Title/Author, aby Chrome viewer nezobrazoval text nad PDF.
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyInvoice.cz');

        // CSS separately (mPDF handluje líp než inline <style> tag)
        if ($rendered['css'] !== '') {
            $mpdf->WriteHTML($rendered['css'], \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->WriteHTML($rendered['body'], \Mpdf\HTMLParserMode::HTML_BODY);

        if (!is_dir(dirname($cachedPath))) {
            @mkdir(dirname($cachedPath), 0755, true);
        }
        // Write to .new sibling first, pak atomický rename — obchází Windows file lock
        // (když je starý PDF otevřený v Chrome PDF viewer, přepis přímo by selhal).
        $tmpPath = $cachedPath . '.new';
        $mpdf->Output($tmpPath, \Mpdf\Output\Destination::FILE);
        if (is_file($cachedPath)) {
            @unlink($cachedPath); // pokud locked, fail silently
        }
        if (!@rename($tmpPath, $cachedPath)) {
            // Rename selhal (target locked) — nech temp, vrať tmpPath přímo
            $cachedPath = $tmpPath;
        }

        $this->updatePdfPath($invoiceId, $cachedPath);

        return $cachedPath;
    }

    /**
     * @return array{body:string, css:string}
     */
    public function renderHtmlAndCss(array $invoice): array
    {
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        // Renderuj template BEZ inline <style> bloku — CSS pošleme do mPDF zvlášť
        $body = $this->renderHtml($invoice, includeCss: false);
        return ['body' => $body, 'css' => $css];
    }

    public function renderHtml(array $invoice, bool $includeCss = true): string
    {
        // Použij snapshots pokud jsou (issued+), jinak živá data
        $supplierData = $this->resolveSupplier($invoice);
        $clientData   = $this->resolveClient($invoice);
        $bankData     = $this->resolveBank($invoice);

        $qrUri = null;
        if ($invoice['varsymbol'] && $invoice['amount_to_pay'] > 0 && $bankData !== null) {
            $qrUri = $this->qr->generate(
                (string) $invoice['currency'],
                (float) $invoice['amount_to_pay'],
                (string) $invoice['varsymbol'],
                $bankData,
                (string) ($supplierData['display_name'] ?? $supplierData['company_name'] ?? 'MyInvoice'),
            );
        }

        $locale = $invoice['language'] ?? 'cs';
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $css = $includeCss && is_file($cssPath) ? (string) file_get_contents($cssPath) : '';

        $twig = $this->twig();

        // Translation helper
        $twig->addFunction(new \Twig\TwigFunction('t', static function (string $cs, string $en) use ($locale) {
            return $locale === 'en' ? $en : $cs;
        }));

        $logoPath = $this->resolveLogoPath($supplierData);

        return $twig->render('invoice.twig', [
            'invoice'           => $invoice,
            'supplier'          => $supplierData,
            'client'            => $clientData,
            'bank'              => $bankData,
            'qr_data_uri'       => $qrUri,
            'locale'            => $locale,
            'doc_type_label'    => $this->docTypeLabel($invoice, $locale),
            'doc_title'         => $this->docTitle($invoice),
            'parent_varsymbol'  => $this->parentVarsymbol($invoice),
            'work_report'       => $this->workReports->findByInvoice((int) $invoice['id']),
            'date_format'       => $locale === 'en' ? 'M j, Y' : 'j. n. Y',
            'decimal_sep'       => $locale === 'en' ? '.' : ',',
            'thousand_sep'      => $locale === 'en' ? ',' : ' ',
            'css'               => $css,
            'logo_path'         => $logoPath,
        ]);
    }

    private function twig(): Environment
    {
        // Vždy nový Environment — addFunction() lze volat jen před init,
        // a po jednom render() se environment zamkne. Cache=false stejně.
        $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/invoice');
        return new Environment($loader, [
            'autoescape' => 'html',
            'cache' => false,
            'strict_variables' => false,
        ]);
    }

    private function resolveSupplier(array $invoice): array
    {
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot']) ? json_decode($invoice['supplier_snapshot'], true) : $invoice['supplier_snapshot'];
            if (is_array($snap)) return $snap;
        }
        return $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0));
    }

    private function resolveClient(array $invoice): array
    {
        if (!empty($invoice['client_snapshot'])) {
            $snap = is_string($invoice['client_snapshot']) ? json_decode($invoice['client_snapshot'], true) : $invoice['client_snapshot'];
            if (is_array($snap)) return $snap;
        }
        // Live data fallback (pro draft)
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM clients c JOIN countries co ON co.id = c.country_id
              WHERE c.id = ?'
        );
        $stmt->execute([$invoice['client_id']]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function resolveBank(array $invoice): ?array
    {
        if (!empty($invoice['bank_snapshot'])) {
            $snap = is_string($invoice['bank_snapshot']) ? json_decode($invoice['bank_snapshot'], true) : $invoice['bank_snapshot'];
            if (is_array($snap)) return $snap;
        }
        // Live: vezmi z currencies podle invoice.currency_id
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_number, bank_code, bank_name, iban, bic FROM currencies WHERE id = ?'
        );
        $stmt->execute([(int) $invoice['currency_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $hasCzk = !empty($row['account_number']) && !empty($row['bank_code']);
        $hasIban = !empty($row['iban']);
        return ($hasCzk || $hasIban) ? $row : null;
    }

    private function getSupplierData(int $supplierId): array
    {
        if ($supplierId <= 0) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM supplier s JOIN countries co ON co.id = s.country_id WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function docTypeLabel(array $invoice, string $locale): string
    {
        $labels = [
            'cs' => [
                'invoice'      => 'Faktura — daňový doklad',
                'proforma'     => 'Zálohová faktura — není daňový doklad',
                'credit_note'  => 'Opravný daňový doklad',
                'cancellation' => 'Storno (interní)',
            ],
            'en' => [
                'invoice'      => 'Invoice — Tax document',
                'proforma'     => 'Proforma invoice — Not a tax document',
                'credit_note'  => 'Credit note — Tax adjustment',
                'cancellation' => 'Cancellation (internal)',
            ],
        ];
        return $labels[$locale][$invoice['invoice_type']] ?? $labels['cs'][$invoice['invoice_type']] ?? '';
    }

    private function docTitle(array $invoice): string
    {
        $vs = $invoice['varsymbol'] ?? ('DRAFT-' . $invoice['id']);
        $t = match ($invoice['invoice_type']) {
            'proforma'     => 'Zálohová faktura',
            'credit_note'  => 'Dobropis',
            'cancellation' => 'Storno',
            default        => 'Faktura',
        };
        return "$t $vs";
    }

    private function parentVarsymbol(array $invoice): ?string
    {
        if (!$invoice['parent_invoice_id']) return null;
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol FROM invoices WHERE id = ?');
        $stmt->execute([$invoice['parent_invoice_id']]);
        return $stmt->fetchColumn() ?: null;
    }

    private function resolveLogoPath(array $supplier): ?string
    {
        $logoPath = $supplier['logo_path'] ?? null;
        if (!$logoPath) return null;
        // Pokud je relativní path, doplň root
        if (!is_file($logoPath)) {
            $abs = Bootstrap::rootDir() . '/' . ltrim($logoPath, '/');
            if (is_file($abs)) return $abs;
            return null;
        }
        return $logoPath;
    }

    /**
     * Smaže cached PDF + vynuluje invoices.pdf_path. Volat po změnách, které
     * ovlivní obsah PDF nad rámec items (např. work_report).
     */
    public function invalidate(int $invoiceId): void
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) return;

        $paths = array_unique(array_filter([
            $invoice['pdf_path'] ?? null,
            $this->cachePath($invoice),
        ]));
        foreach ($paths as $p) {
            if (is_file($p)) @unlink($p);
        }
        $this->db->pdo()->prepare('UPDATE invoices SET pdf_path = NULL, pdf_generated_at = NULL WHERE id = ?')
            ->execute([$invoiceId]);
    }

    /**
     * Bulk invalidate — pro všechny faktury v dané měně, které renderují bank info live
     * (drafts + faktury bez snapshotu). Issued/sent/paid s bank_snapshot mají immutable kopii
     * bank údajů a invalidace by zbytečně regenerovala stejný PDF.
     *
     * Vrací počet invalidovaných faktur.
     */
    public function invalidateByCurrency(int $currencyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE currency_id = ? AND (status = "draft" OR bank_snapshot IS NULL)'
        );
        $stmt->execute([$currencyId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($ids as $id) $this->invalidate($id);
        return count($ids);
    }

    private function cachePath(array $invoice): string
    {
        $rootDir = Bootstrap::rootDir();
        $issueDate = new \DateTimeImmutable($invoice['issue_date']);
        // Multi-supplier: supplier subfolder zabraňuje kolizi varsymbolu mezi suppliery
        $supplierId = (int) ($invoice['supplier_id'] ?? 1);
        $dir = $rootDir . '/storage/invoices/sup-' . $supplierId . '/' . $issueDate->format('Y-m');

        $vs = $invoice['varsymbol'] ?? ('draft-' . $invoice['id']);
        $type = match ($invoice['invoice_type']) {
            'proforma'     => 'Proforma',
            'credit_note'  => 'Dobropis',
            'cancellation' => 'Storno',
            default        => 'Faktura',
        };
        return "$dir/$type-$vs.pdf";
    }

    private function updatePdfPath(int $invoiceId, string $path): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices SET pdf_path = ?, pdf_generated_at = NOW() WHERE id = ?'
        )->execute([$path, $invoiceId]);
    }
}
