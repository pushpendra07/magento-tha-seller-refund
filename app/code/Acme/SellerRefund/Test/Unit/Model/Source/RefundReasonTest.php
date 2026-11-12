<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Source;

use Acme\SellerRefund\Model\RefundValidator;
use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Source\RefundReason;
use Acme\SellerRefund\Model\Submission\RefundSubmission;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;

/**
 * Every reason an operator can pick on the entry form must be accepted by the server-side
 * validator (FRD section 13), otherwise no refund can be submitted from the admin.
 */
class RefundReasonTest extends TestCase
{
    public function testOptionsMatchTheControlledList(): void
    {
        $values = array_column((new RefundReason())->toOptionArray(), 'value');

        self::assertSame(RefundValidator::ALLOWED_REASONS, $values);
    }

    /**
     * @dataProvider reasonProvider
     */
    public function testEveryOfferedReasonPassesValidation(string $reasonCode): void
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getQtyOrdered')->willReturn('1');
        $resolver = $this->createMock(SellerLineResolver::class);
        $resolver->method('sellerLines')->willReturn([5 => $item]);

        (new RefundValidator($resolver))->validate(
            $this->createMock(OrderInterface::class),
            new RefundSubmission($reasonCode, [5 => '1']),
            []
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reasonProvider(): array
    {
        $cases = [];
        foreach ((new RefundReason())->toOptionArray() as $option) {
            $cases[$option['value']] = [$option['value']];
        }

        return $cases;
    }
}
