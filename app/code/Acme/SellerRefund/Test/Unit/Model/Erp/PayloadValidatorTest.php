<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Erp;

use Acme\SellerRefund\Exception\ValidationException;
use Acme\SellerRefund\Model\Erp\PayloadValidator;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Local checks that stop an ERP request which can never succeed (FRD section 9). Pure unit
 * coverage: no database, no HTTP.
 */
class PayloadValidatorTest extends TestCase
{
    /**
     * @var PayloadValidator
     */
    private PayloadValidator $validator;

    protected function setUp(): void
    {
        $taxCodes = $this->createMock(TaxCodeResolver::class);
        $taxCodes->method('assertKnown')->willReturnCallback(static function (string $code): void {
            if (!in_array($code, ['999', '010', '008'], true)) {
                throw new \InvalidArgumentException('Unknown code');
            }
        });
        $this->validator = new PayloadValidator($taxCodes);
    }

    public function testValidCreatePayloadPasses(): void
    {
        $this->validator->validateCreate($this->createPayload());

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider invalidCreateProvider
     *
     * @param callable $break changes a valid payload into an invalid one
     * @param string $expected part of the expected message
     */
    public function testInvalidCreatePayloadIsRejected(callable $break, string $expected): void
    {
        $payload = $this->createPayload();
        $break($payload);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($expected);
        $this->validator->validateCreate($payload);
    }

    /**
     * @return array<string, array{callable, string}>
     */
    public static function invalidCreateProvider(): array
    {
        return [
            'missing refund_no (ERP would open a new credit note each time)' => [
                static function (array &$p): void {
                    $p['refund_no'] = '';
                },
                'refund_no is missing',
            ],
            'local option id instead of business tax code (PR 2 finding 5)' => [
                static function (array &$p): void {
                    $p['lines'][0]['taxes'][0]['code'] = '7';
                },
                'tax code "7" is not a business tax code',
            ],
            'fractional quantity cast to 0' => [
                static function (array &$p): void {
                    $p['lines'][0]['quantity'] = 0;
                },
                'quantity must be a whole number of at least 1',
            ],
            'no lines' => [
                static function (array &$p): void {
                    $p['lines'] = [];
                },
                'there are no lines',
            ],
            'amount not a 4-decimal string' => [
                static function (array &$p): void {
                    $p['lines'][0]['amount'] = '1200';
                },
                'amount is not a 4-decimal amount',
            ],
            'totals do not add up' => [
                static function (array &$p): void {
                    $p['grand_total'] = '1321.0000';
                },
                'do not add up to grand_total',
            ],
            'wrong tax mode' => [
                static function (array &$p): void {
                    $p['tax_mode'] = 'TAX_INCLUDED';
                },
                'tax_mode must be TAX_EXCLUDED',
            ],
        ];
    }

    public function testValidConfirmPasses(): void
    {
        $this->validator->validateConfirm(
            'ERP-8000123',
            ['transaction_number' => 'TXN-001', 'transaction_date' => '2026-11-12']
        );

        $this->addToAssertionCount(1);
    }

    /**
     * BR-10: the ERP stub accepts an empty reference, so only this check enforces it.
     */
    public function testConfirmWithoutTransactionReferenceIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('transaction_number is missing');
        $this->validator->validateConfirm(
            'ERP-8000123',
            ['transaction_number' => '', 'transaction_date' => '2026-11-12']
        );
    }

    public function testConfirmWithInvalidDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('transaction_date is not a valid date');
        $this->validator->validateConfirm(
            'ERP-8000123',
            ['transaction_number' => 'TXN-001', 'transaction_date' => '0000-00-00']
        );
    }

    /**
     * An empty id would call GET /refunds/ and be retried eight times as a "temporary" 404.
     */
    public function testEmptyErpRefundIdIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is not a valid ERP identifier');
        $this->validator->validateErpRefundId('');
    }

    /**
     * A valid request in the shape PayloadBuilder produces: 1200 + 120 tax + 0 shipping = 1320.
     *
     * @return array<string, mixed>
     */
    private function createPayload(): array
    {
        return [
            'refund_no' => 'SR-20261112-000001',
            'seller_order_id' => 'SO-1002',
            'tax_mode' => 'TAX_EXCLUDED',
            'currency' => 'JPY',
            'lines' => [
                [
                    'sku' => 'SELLER-RED-01',
                    'quantity' => 1,
                    'amount' => '1200.0000',
                    'taxes' => [['code' => '010', 'amount' => '120.0000']],
                ],
            ],
            'shipping_amount' => '0.0000',
            'grand_total' => '1320.0000',
        ];
    }
}
