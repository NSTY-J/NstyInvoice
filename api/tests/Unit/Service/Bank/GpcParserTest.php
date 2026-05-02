<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\GpcParser;
use PHPUnit\Framework\TestCase;

/**
 * Layout reference: https://wiki.zdechov.net/GPC_export_z_Fio_banky
 * Datum format: DDMMYY (NE YYMMDD)
 */
final class GpcParserTest extends TestCase
{
    public function testParsesHeaderAnd075Transactions(): void
    {
        // 074 header — fixed-width per zdechov wiki:
        // pos 1-3: "074", 4-19: account, 20-39: name, 40-45: old_balance_date,
        // 46-59: old_balance, 60: sign, 61-74: new_balance, 75: sign,
        // 76-89: debit, 90: sign, 91-104: credit, 105: sign,
        // 106-108: sequence, 109-114: statement_date
        $header = '074'
            . str_pad('1234567890', 16)                            // own account
            . str_pad('Test Account', 20)                          // name
            . '300426'                                              // old_balance_date 30.4.2026
            . str_pad('100000', 14, '0', STR_PAD_LEFT) . '+'       // old_balance 1000.00
            . str_pad('250000', 14, '0', STR_PAD_LEFT) . '+'       // new_balance 2500.00
            . str_pad('100000', 14, '0', STR_PAD_LEFT) . '+'       // debit
            . str_pad('250000', 14, '0', STR_PAD_LEFT) . '+'       // credit
            . '001'                                                 // sequence
            . '010526';                                             // statement_date 1.5.2026 DDMMYY

        // 075 transaction
        // pos 1-3: "075", 4-19: own, 20-35: counterparty, 36-48: doc, 49-60: amount,
        // 61: code, 62-71: VS, 72-73: filler, 74-77: bank_code, 78-81: KS,
        // 82-91: SS, 92-97: value_date filler, 98-117: client_name, 118-122: currency,
        // 123-128: posting_date DDMMYY
        $tx = '075'
            . str_pad('1234567890', 16)                            // own account
            . str_pad('9876543210123456', 16)                      // counterparty
            . str_pad('DOC0000000001', 13)                         // doc number
            . str_pad('1815000', 12, '0', STR_PAD_LEFT)            // amount cents = 18150.00
            . '2'                                                   // code (2 = credit)
            . str_pad('202605001', 10, '0', STR_PAD_LEFT)          // VS
            . '00'                                                  // filler 2
            . '0100'                                                // bank code (KB)
            . '0308'                                                // KS
            . str_pad('0', 10, '0', STR_PAD_LEFT)                  // SS
            . '020526'                                              // value_date filler 6
            . str_pad('Platba ACME', 20)                           // description
            . '00203'                                               // currency 5 (00203 = CZK)
            . '020526';                                             // posting_date DDMMYY 2.5.2026

        $parser = new GpcParser();
        $r = $parser->parse($header . "\n" . $tx);

        // Header
        self::assertSame('1234567890', $r['header']['account_number']);
        self::assertSame('2026-05-01', $r['header']['statement_date']);
        self::assertSame(1000.0,  $r['header']['prev_balance']);
        self::assertSame(2500.0,  $r['header']['curr_balance']);
        self::assertSame('001',   $r['header']['statement_number']);

        // Transaction
        self::assertCount(1, $r['transactions']);
        $t = $r['transactions'][0];
        self::assertSame('2026-05-02', $t['posted_at']);
        self::assertSame(18150.0,      $t['amount']);
        self::assertSame('202605001',  $t['variable_symbol']);
        self::assertSame('308',        $t['constant_symbol']);   // ltrim('0') v parser
        self::assertSame('0100',       $t['counterparty_bank']);
        self::assertSame('Platba ACME', $t['description']);
        self::assertSame('CZK',        $t['currency']);
    }

    public function testCurrencyMappingFromIsoNumeric(): void
    {
        // 00203 = CZK, 00978 = EUR, 00840 = USD — verify normalize ltrim works
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+'
            . '001' . '010126';

        $mkTx = function (string $currencyField): string {
            return '075'
                . str_pad('1', 16) . str_pad('1', 16) . str_pad('D', 13)
                . str_pad('100', 12, '0', STR_PAD_LEFT)
                . '2'
                . str_pad('1', 10, '0', STR_PAD_LEFT)
                . '00' . '0100' . '0000'
                . str_pad('0', 10, '0', STR_PAD_LEFT)
                . '010126'
                . str_pad('Test', 20)
                . $currencyField                              // 5 chars
                . '010126';
        };

        $parser = new GpcParser();
        self::assertSame('CZK', $parser->parse($header . "\n" . $mkTx('00203'))['transactions'][0]['currency']);
        self::assertSame('EUR', $parser->parse($header . "\n" . $mkTx('00978'))['transactions'][0]['currency']);
        self::assertSame('USD', $parser->parse($header . "\n" . $mkTx('00840'))['transactions'][0]['currency']);
        self::assertNull($parser->parse($header . "\n" . $mkTx('     '))['transactions'][0]['currency']);
    }

    public function testNegativeAmountForDebitCode(): void
    {
        // Posting code 1 = debit → záporná amount
        $header = '074' . str_pad('1', 16) . str_pad('', 20) . '010126'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010126';
        $tx = '075' . str_pad('1', 16) . str_pad('1', 16) . str_pad('D', 13)
            . str_pad('50000', 12, '0', STR_PAD_LEFT)
            . '1'                                                   // 1 = debit
            . str_pad('1', 10, '0', STR_PAD_LEFT)
            . '00' . '0100' . '0000'
            . str_pad('0', 10, '0', STR_PAD_LEFT) . '010126'
            . str_pad('Outgoing', 20) . '00203' . '010126';

        $parser = new GpcParser();
        self::assertSame(-500.0, $parser->parse($header . "\n" . $tx)['transactions'][0]['amount']);
    }

    public function testThrowsOnMissingHeader(): void
    {
        $parser = new GpcParser();
        $this->expectException(\RuntimeException::class);
        $parser->parse("nějaký nesmyslný obsah bez 074 řádku");
    }
}
