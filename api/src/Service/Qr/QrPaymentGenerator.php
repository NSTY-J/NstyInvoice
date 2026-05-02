<?php

declare(strict_types=1);

namespace MyInvoice\Service\Qr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use MyInvoice\Infrastructure\Config\Config;
use Psr\Log\LoggerInterface;
use Rikudou\CzQrPayment\QrPayment as CzQrPayment;
use Rikudou\Iban\Iban\CzechIbanAdapter;
use SepaQr\SepaQrData;

/**
 * Generuje QR kód pro platbu:
 *   CZK → SPAYD format (Rikudou\CzQrPayment, čte každá česká bankovní app)
 *   EUR / non-CZK → SEPA EPC (smhg/sepa-qr-data)
 *
 * Vrací data URI (base64-encoded PNG) vhodný jako `<img src="...">` v HTML/PDF.
 */
final class QrPaymentGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array $bank   bank_snapshot z faktury
     *                      pro CZK: ['account_number','bank_code','iban']
     *                      pro EUR: ['iban','bic']
     * @param string $supplierName   pro SEPA QR
     * @return string|null  data URI nebo null pokud nelze vygenerovat
     */
    public function generate(
        string $currency,
        float $amount,
        string $varsymbol,
        array $bank,
        string $supplierName = '',
    ): ?string {
        if ($amount <= 0) {
            return null;
        }

        try {
            $payload = $currency === 'CZK'
                ? $this->buildCzk($amount, $varsymbol, $bank)
                : $this->buildSepa($amount, $varsymbol, $bank, $supplierName);
        } catch (\Throwable $e) {
            $this->logger->warning('QR generation failed: ' . $e->getMessage(), [
                'currency' => $currency,
                'varsymbol' => $varsymbol,
            ]);
            return null;
        }

        if ($payload === null) {
            return null;
        }

        // PNG s pevnou velikostí — mPDF lépe handluje než SVG (SVG bez explicit size se nafukuje)
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel'        => EccLevel::M,
            'scale'           => 8,
            'imageBase64'     => true,
            'quietzoneSize'   => 2,
        ]);
        return (new QRCode($options))->render($payload);
    }

    private function buildCzk(float $amount, string $varsymbol, array $bank): ?string
    {
        $accountNumber = (string) ($bank['account_number'] ?? '');
        $bankCode      = (string) ($bank['bank_code'] ?? '');
        if ($accountNumber === '' || $bankCode === '') {
            return null;
        }

        // rikudou/czqrpayment v5: konstruktor přijímá IbanInterface
        $payment = new CzQrPayment(new CzechIbanAdapter($accountNumber, $bankCode));
        $payment->setAmount($amount)
            ->setCurrency('CZK')
            ->setVariableSymbol($varsymbol)
            ->setConstantSymbol((string) $this->config->get('qr.czk_constant_symbol', '0308'))
            ->setDueDate(new \DateTimeImmutable())
            ->setComment('Faktura ' . $varsymbol);

        return $payment->getQrString();
    }

    private function buildSepa(float $amount, string $varsymbol, array $bank, string $name): string
    {
        $iban = trim(str_replace(' ', '', (string) ($bank['iban'] ?? '')));
        if ($iban === '') {
            throw new \RuntimeException('IBAN je povinný pro SEPA QR');
        }

        return (string) (new SepaQrData())
            ->setName($name !== '' ? $name : 'MyInvoice')
            ->setIban($iban)
            ->setRemittanceText('Faktura ' . $varsymbol)
            ->setAmount($amount);
    }
}
