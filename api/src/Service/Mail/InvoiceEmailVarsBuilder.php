<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Qr\QrPaymentGenerator;

/**
 * Sestavuje template variables pro invoice_send.{cs|en}.{html|txt}.twig.
 * Používá se z SendEmailAction i SendTestEmailAction.
 */
final class InvoiceEmailVarsBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly QrPaymentGenerator $qr,
    ) {}

    /**
     * Variables pro `invoice_reminder.{locale}.{html,txt}.twig`.
     * Stejný shape jako build() + extra `days_overdue` pro template.
     */
    public function buildReminder(array $invoice, int $daysOverdue, string $locale): array
    {
        $varsymbol = (string) ($invoice['varsymbol'] ?? '');
        $supplier = $this->resolveSupplierName($invoice, false);

        $subject = $locale === 'en'
            ? "Reminder — invoice {$varsymbol} is {$daysOverdue} day" . ($daysOverdue === 1 ? '' : 's') . ' overdue'
            : "Upomínka — faktura {$varsymbol} je {$daysOverdue} " . ($daysOverdue === 1 ? 'den' : ($daysOverdue < 5 ? 'dny' : 'dní')) . ' po splatnosti';
        if ($supplier !== '') {
            $subject .= " — {$supplier}";
        }

        return [
            'invoice'       => $invoice,
            'client_name'   => $invoice['client_company_name'] ?? '',
            'amount_to_pay' => (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat']),
            'days_overdue'  => $daysOverdue,
            'subject'       => $subject,
            'qr_data_uri'   => $this->generateQr($invoice),
            'supplier'      => $this->loadSupplierFooter($invoice),
            'is_test'       => false,
        ];
    }

    public function build(array $invoice, bool $isTest, string $locale): array
    {
        $type = (string) $invoice['invoice_type'];
        $varsymbol = (string) ($invoice['varsymbol'] ?? '');
        $amount = (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat']);

        $typeLabel = match ($type) {
            'proforma'    => $locale === 'en' ? 'proforma invoice' : 'zálohovou fakturu',
            'credit_note' => $locale === 'en' ? 'credit note' : 'opravný daňový doklad',
            default       => $locale === 'en' ? 'invoice' : 'fakturu',
        };

        if ($locale === 'en') {
            $greeting = 'Hello,';
            $intro = "we're sending you {$typeLabel} <strong>č. {$varsymbol}</strong>.";
            $intro_plain = "we're sending you {$typeLabel} č. {$varsymbol}.";
        } else {
            $greeting = 'Dobrý den,';
            $intro = "v příloze posíláme {$typeLabel} <strong>č. {$varsymbol}</strong>.";
            $intro_plain = "v příloze posíláme {$typeLabel} č. {$varsymbol}.";
        }

        return [
            'greeting'      => $greeting,
            'intro'         => $intro,
            'intro_plain'   => $intro_plain,
            'invoice'       => $invoice,
            'client_name'   => $invoice['client_company_name'] ?? '',
            'amount_to_pay' => $amount,
            'is_test'       => $isTest,
            'subject'       => $this->buildSubject($invoice, $isTest, $locale),
            'qr_data_uri'   => $this->generateQr($invoice),
            'supplier'      => $this->loadSupplierFooter($invoice),
        ];
    }

    private function generateQr(array $invoice): ?string
    {
        if (empty($invoice['varsymbol'])) return null;
        if (($invoice['amount_to_pay'] ?? 0) <= 0) return null;

        // Bank z snapshot (issued+) nebo live z currencies
        $bank = null;
        if (!empty($invoice['bank_snapshot'])) {
            $snap = is_string($invoice['bank_snapshot']) ? json_decode($invoice['bank_snapshot'], true) : $invoice['bank_snapshot'];
            if (is_array($snap)) $bank = $snap;
        }
        if ($bank === null && !empty($invoice['currency_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT account_number, bank_code, bank_name, iban, bic FROM currencies WHERE id = ?'
            );
            $stmt->execute([(int) $invoice['currency_id']]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) $bank = $row;
        }
        if ($bank === null) return null;

        $supplierName = $this->resolveSupplierName($invoice, true);

        return $this->qr->generate(
            (string) $invoice['currency'],
            (float) $invoice['amount_to_pay'],
            (string) $invoice['varsymbol'],
            $bank,
            (string) ($supplierName ?: 'MyInvoice'),
        );
    }

    private function buildSubject(array $invoice, bool $isTest, string $locale): string
    {
        $varsymbol = $invoice['varsymbol'] ?? '';
        $supplier = $this->resolveSupplierName($invoice, false);
        $prefix = $isTest ? '[TEST] ' : '';

        if ($locale === 'en') {
            return "{$prefix}Invoice {$varsymbol}" . ($supplier ? " — {$supplier}" : '');
        }
        return "{$prefix}Faktura {$varsymbol}" . ($supplier ? " — {$supplier}" : '');
    }

    /**
     * Vrátí jméno supplier pro fakturu — preferuje snapshot (issued+),
     * fallback live `supplier` tabulka přes invoice.supplier_id.
     * $preferDisplayName=true → COALESCE(display_name, company_name) (vhodné pro QR jméno odesílatele).
     */
    private function resolveSupplierName(array $invoice, bool $preferDisplayName): string
    {
        // 1. Snapshot (immutable po vystavení)
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                if ($preferDisplayName) {
                    return (string) ($snap['display_name'] ?: ($snap['company_name'] ?? ''));
                }
                return (string) ($snap['company_name'] ?? '');
            }
        }
        // 2. Live lookup přes supplier_id
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        if ($sid <= 0) return '';
        $col = $preferDisplayName ? 'COALESCE(display_name, company_name)' : 'company_name';
        $stmt = $this->db->pdo()->prepare("SELECT $col FROM supplier WHERE id = ?");
        $stmt->execute([$sid]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Vrátí kompletní supplier kontext pro patičku emailu (podle invoice.supplier_id).
     * Preferuje supplier_snapshot, fallback na live supplier+countries lookup.
     */
    private function loadSupplierFooter(array $invoice): ?array
    {
        // 1. Snapshot
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                return [
                    'company_name' => $snap['company_name'] ?? '',
                    'display_name' => $snap['display_name'] ?? null,
                    'tagline'      => $snap['tagline'] ?? null,
                    'street'       => $snap['street'] ?? '',
                    'city'         => $snap['city'] ?? '',
                    'zip'          => $snap['zip'] ?? '',
                    'country'      => $snap['country_name_cs'] ?? '',
                    'email'        => $snap['email'] ?? null,
                    'phone'        => $snap['phone'] ?? null,
                    'web'          => $snap['web'] ?? null,
                ];
            }
        }
        // 2. Live
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        if ($sid <= 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                    s.email, s.phone, s.web, co.name_cs AS country
               FROM supplier s
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
