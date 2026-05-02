<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\VarsymbolGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Testuje render() — pure metoda bez DB, fakeovací konstruktor přes reflexi.
 */
final class VarsymbolRenderTest extends TestCase
{
    private VarsymbolGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = (new \ReflectionClass(VarsymbolGenerator::class))->newInstanceWithoutConstructor();
    }

    public function testInvoiceFormat(): void
    {
        // 2026-04, counter=42 → "260442 padded" závisí na template
        $date = new \DateTimeImmutable('2026-04-15');
        self::assertSame('2604042', $this->gen->render('{YY}{MM}{CCC}', $date, 42));
    }

    public function testProformaPrefix(): void
    {
        $date = new \DateTimeImmutable('2026-04-15');
        self::assertSame('92604042', $this->gen->render('9{YY}{MM}{CCC}', $date, 42));
    }

    public function testCreditNotePrefix(): void
    {
        $date = new \DateTimeImmutable('2026-12-15');
        self::assertSame('72612001', $this->gen->render('7{YY}{MM}{CCC}', $date, 1));
    }

    public function testFourDigitYear(): void
    {
        $date = new \DateTimeImmutable('2026-04-15');
        self::assertSame('202604042', $this->gen->render('{YYYY}{MM}{CCC}', $date, 42));
    }

    public function testCounterPaddingByLength(): void
    {
        $date = new \DateTimeImmutable('2026-04-15');
        // CCCCCC = 6 míst → "000042"
        self::assertSame('F-2026/000042', $this->gen->render('F-{YYYY}/{CCCCCC}', $date, 42));
    }

    public function testCounterDoesntTruncate(): void
    {
        // Pokud counter > padding, pad neořeže (ponechá natural width)
        $date = new \DateTimeImmutable('2026-04-15');
        self::assertSame('20260499999', $this->gen->render('{YYYY}{MM}{C}', $date, 99999));
    }

    public function testMultipleCounterTokensAllReplaced(): void
    {
        // Edge case: víc {C} v jednom template — všechny dostanou stejnou hodnotu
        $date = new \DateTimeImmutable('2026-04-15');
        self::assertSame('042-042', $this->gen->render('{CCC}-{CCC}', $date, 42));
    }
}
