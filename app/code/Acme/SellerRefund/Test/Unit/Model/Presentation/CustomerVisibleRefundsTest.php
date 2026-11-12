<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Presentation;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Presentation\CustomerVisibleRefunds;
use PHPUnit\Framework\TestCase;

/**
 * The storefront must not present a refund that will never be paid (FRD 10.4).
 */
class CustomerVisibleRefundsTest extends TestCase
{
    /**
     * Failed, cancelled and draft refunds are hidden; every registered refund stays, in order.
     *
     * @return void
     */
    public function testHidesRefundsThatWillNeverBePaid(): void
    {
        $statuses = [
            RefundInterface::STATUS_CALCULATED,
            RefundInterface::STATUS_FAILED,
            RefundInterface::STATUS_CASH_REFUND_PENDING,
            RefundInterface::STATUS_CANCELLED,
            RefundInterface::STATUS_CASH_REFUNDED,
            RefundInterface::STATUS_DRAFT,
            RefundInterface::STATUS_ERP_CONFIRM_PENDING,
            RefundInterface::STATUS_ERP_CONFIRMED,
        ];
        $refunds = [];
        foreach ($statuses as $status) {
            $refund = $this->createMock(RefundInterface::class);
            $refund->method('getStatus')->willReturn($status);
            $refunds[] = $refund;
        }
        $repository = $this->createMock(RefundRepositoryInterface::class);
        $repository->method('getByOrderId')->with(9)->willReturn($refunds);

        $visible = (new CustomerVisibleRefunds($repository))->forOrder(9);

        self::assertSame(
            [
                RefundInterface::STATUS_CALCULATED,
                RefundInterface::STATUS_CASH_REFUND_PENDING,
                RefundInterface::STATUS_CASH_REFUNDED,
                RefundInterface::STATUS_ERP_CONFIRM_PENDING,
                RefundInterface::STATUS_ERP_CONFIRMED,
            ],
            array_map(static fn (RefundInterface $refund): string => $refund->getStatus(), $visible)
        );
    }
}
