<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Event;

use Acme\SellerRefund\Model\Event\PayloadRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Audit payloads must not keep credentials, bank details or personal data (FRD 12.4).
 */
class PayloadRedactorTest extends TestCase
{
    /**
     * A sensitive key is redacted whole, even when its value is an array whose keys look harmless.
     *
     * @return void
     */
    public function testSensitiveKeyWithNestedValueIsRedactedWhole(): void
    {
        $result = (new PayloadRedactor())->redact([
            'refund_no' => 'SR-20261113-000001',
            'bank_account' => ['number' => '1234567', 'holder' => 'Rei Tanaka'],
            'customer' => ['email' => 'someone@example.com', 'name' => 'Rei'],
        ]);

        self::assertSame('SR-20261113-000001', $result['refund_no']);
        self::assertSame('[redacted]', $result['bank_account']);
        self::assertSame('[redacted]', $result['customer']['email']);
        self::assertSame('Rei', $result['customer']['name'], 'Non-sensitive nested values are kept.');
    }
}
