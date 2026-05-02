<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SecretEncryptionTest extends TestCase
{
    private function makeWithKey(string $base64Key): SecretEncryption
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        // Inject config table přes reflexi — keep test bez DI containeru
        $prop = new \ReflectionProperty($config, 'data');
        $prop->setValue($config, ['app' => ['secret_encryption_key' => $base64Key, 'pepper' => '']]);
        return new SecretEncryption($config);
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        $plain = 'JBSWY3DPEHPK3PXP'; // typický base32 TOTP secret
        $cipher = $svc->encrypt($plain);
        self::assertNotSame($plain, $cipher);
        self::assertStringStartsWith('enc:v1:', $cipher);
        self::assertSame($plain, $svc->decrypt($cipher));
    }

    public function testIsEncrypted(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        self::assertFalse($svc->isEncrypted('plain-text-secret'));
        self::assertTrue($svc->isEncrypted($svc->encrypt('plain-text-secret')));
    }

    public function testLegacyPlaintextPassesThrough(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        // Legacy entries (před šifrováním) jsou bez prefixu — decrypt je vrátí beze změny
        self::assertSame('legacy-plain', $svc->decrypt('legacy-plain'));
    }

    public function testTwoEncryptionsOfSameInputDiffer(): void
    {
        // Random nonce → každý encrypt je jiný blob (důležité pro chosen-plaintext security)
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        $a = $svc->encrypt('same-input');
        $b = $svc->encrypt('same-input');
        self::assertNotSame($a, $b);
        self::assertSame('same-input', $svc->decrypt($a));
        self::assertSame('same-input', $svc->decrypt($b));
    }

    public function testInvalidBase64KeyThrows(): void
    {
        $svc = $this->makeWithKey('not-valid-base64-32-bytes!!!');
        $this->expectException(\RuntimeException::class);
        $svc->encrypt('x');
    }

    public function testTamperingDetected(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        $cipher = $svc->encrypt('original');
        // Změním poslední znak ciphertextu → GCM tag se nesedí → exception
        $tampered = substr($cipher, 0, -1) . (substr($cipher, -1) === 'A' ? 'B' : 'A');
        $this->expectException(\RuntimeException::class);
        $svc->decrypt($tampered);
    }
}
