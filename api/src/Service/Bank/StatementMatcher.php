<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Matchne bankovní transakci na fakturu podle VS + amount.
 *
 * Strategie:
 *   1. Příchozí (amount > 0) — hledá unpaid invoice se shodným varsymbol
 *      a) amount == amount_to_pay → 'auto_exact', faktura → paid
 *      b) |amount - amount_to_pay| <= 1 Kč → 'auto_partial' (jen log, faktura zůstane)
 *   2. Odchozí (amount < 0) — neshodujeme (může být refund / náš výdaj)
 *
 * Multi-supplier: VS je unique per (supplier_id, varsymbol). Matcher určuje
 * supplier_id z bank_statement.account_number → currencies.account_number → supplier_id.
 * Pokud žádná currency neodpovídá účtu (bank statement nepatří žádnému supplierovi),
 * vrátí 'unmatched/unknown_supplier'.
 */
final class StatementMatcher
{
    public function __construct(private readonly Connection $db) {}

    public function match(int $transactionId): array
    {
        $pdo = $this->db->pdo();
        $tx = $pdo->prepare(
            'SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $tx->execute([$transactionId]);
        $row = $tx->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'unmatched', 'reason' => 'transaction_not_found'];
        }
        $vs = $row['variable_symbol'];
        $amount = (float) $row['amount'];
        if ($amount <= 0 || !$vs) {
            return ['status' => 'unmatched', 'reason' => 'no_vs_or_outgoing'];
        }

        // Určení supplier_id z bank účtu (currencies.account_number + bank_code).
        // Normalizace přes AccountNumberNormalizer (řeší zero-padding a prefix).
        $supplierId = 0;
        if (!empty($row['recipient_account'])) {
            $sql = 'SELECT supplier_id, account_number FROM currencies WHERE account_number IS NOT NULL';
            $params = [];
            if (!empty($row['recipient_bank'])) {
                $sql .= ' AND bank_code = ?';
                $params[] = $row['recipient_bank'];
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
                if (AccountNumberNormalizer::equals((string) $candidate['account_number'], (string) $row['recipient_account'])) {
                    $supplierId = (int) $candidate['supplier_id'];
                    break;
                }
            }
        }
        if ($supplierId === 0) {
            return ['status' => 'unmatched', 'reason' => 'unknown_supplier_for_account'];
        }

        // Najdi fakturu s VS = transakce.VS, supplier scope, status in (issued, sent, reminded), amount_to_pay sedí
        $stmt = $pdo->prepare(
            "SELECT i.id, i.varsymbol, i.amount_to_pay, i.status, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.varsymbol = ?
                AND i.status IN ('issued', 'sent', 'reminded')
                AND i.invoice_type = 'invoice'
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $vs]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return ['status' => 'unmatched', 'reason' => 'no_unpaid_invoice_with_vs'];
        }

        $diff = abs($amount - (float) $inv['amount_to_pay']);
        if ($diff < 0.01) {
            // Exact match — automaticky označit jako paid
            $pdo->prepare(
                "UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?"
            )->execute([$row['posted_at'], $inv['id']]);
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'auto_exact', matched_at = NOW()
                  WHERE id = ?"
            )->execute([$inv['id'], $transactionId]);
            return ['status' => 'auto_exact', 'invoice_id' => (int) $inv['id'], 'varsymbol' => $vs];
        }
        if ($diff <= 1.0) {
            // Partial match — flag, ale nepaint paid (uživatel rozhodne)
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'auto_partial', matched_at = NOW()
                  WHERE id = ?"
            )->execute([$inv['id'], $transactionId]);
            return ['status' => 'auto_partial', 'invoice_id' => (int) $inv['id'], 'diff' => $diff];
        }

        return ['status' => 'unmatched', 'reason' => 'amount_mismatch', 'expected' => $inv['amount_to_pay'], 'got' => $amount];
    }
}
