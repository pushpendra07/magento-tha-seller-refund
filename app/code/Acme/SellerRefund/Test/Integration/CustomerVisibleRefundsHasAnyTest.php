<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Model\Presentation\CustomerVisibleRefunds;
use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;

/**
 * The one-row check behind the "Seller Refunds" order tab.
 */
class CustomerVisibleRefundsHasAnyTest extends DbTransactionTestCase
{
    /**
     * A cancelled refund alone does not count; a kept refund does.
     *
     * @return void
     */
    public function testOnlyVisibleRefundsCount(): void
    {
        $seed = $this->get(SeedOrders::class);
        $processor = $this->get(RefundProcessor::class);
        $order = $seed->loadByIncrementId('SR-ORD-1001');
        $orderId = (int) $order->getEntityId();
        $lineId = $seed->firstSellerLineId($order);
        $visible = $this->create(CustomerVisibleRefunds::class);

        $cancelled = $processor->submit($orderId, $seed->partialSubmission($lineId, '1'));
        $processor->cancel((int) $cancelled->getEntityId(), null);
        self::assertFalse($visible->hasAny($orderId), 'A cancelled refund is not shown.');

        $processor->submit($orderId, $seed->partialSubmission($lineId, '1'));
        self::assertTrue($visible->hasAny($orderId));
        self::assertCount(1, $visible->forOrder($orderId), 'hasAny() and forOrder() agree.');
    }
}
