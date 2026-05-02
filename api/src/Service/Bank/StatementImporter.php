<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Persist parsed GPC do DB. Dedupe podle file_hash.
 */
final class StatementImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly GpcParser $parser,
        private readonly StatementMatcher $matcher,
    ) {}

    /**
     * @return array{statement_id:int, transactions:int, matched:int, duplicate:bool}
     */
    public function import(string $content, string $fileName, ?int $userId): array
    {
        $hash = hash('sha256', $content);
        $pdo = $this->db->pdo();

        // Dedupe
        $exists = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
        $exists->execute([$hash]);
        $existingId = $exists->fetchColumn();
        if ($existingId !== false) {
            return ['statement_id' => (int) $existingId, 'transactions' => 0, 'matched' => 0, 'duplicate' => true];
        }

        $parsed = $this->parser->parse($content);
        $h = $parsed['header'];

        $pdo->prepare(
            'INSERT INTO bank_statements
                 (file_name, file_hash, account_number, statement_number, statement_date,
                  prev_balance, curr_balance, credit_total, debit_total, transaction_count, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fileName, $hash, $h['account_number'], $h['statement_number'], $h['statement_date'],
            $h['prev_balance'], $h['curr_balance'], $h['credit_total'], $h['debit_total'],
            count($parsed['transactions']), $userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions
                 (statement_id, posted_at, amount, currency, variable_symbol, constant_symbol, specific_symbol,
                  counterparty_account, counterparty_bank, counterparty_name, description, bank_ref)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $matched = 0;
        foreach ($parsed['transactions'] as $tx) {
            $insertTx->execute([
                $statementId, $tx['posted_at'], $tx['amount'], $tx['currency'] ?? null,
                $tx['variable_symbol'], $tx['constant_symbol'], $tx['specific_symbol'],
                $tx['counterparty_account'], $tx['counterparty_bank'], $tx['counterparty_name'],
                $tx['description'], $tx['bank_ref'],
            ]);
            $txId = (int) $pdo->lastInsertId();
            $r = $this->matcher->match($txId);
            if (in_array($r['status'], ['auto_exact', 'auto_partial'], true)) {
                $matched++;
            }
        }

        $pdo->prepare('UPDATE bank_statements SET matched_count = ? WHERE id = ?')
            ->execute([$matched, $statementId]);

        return [
            'statement_id' => $statementId,
            'transactions' => count($parsed['transactions']),
            'matched'      => $matched,
            'duplicate'    => false,
        ];
    }
}
