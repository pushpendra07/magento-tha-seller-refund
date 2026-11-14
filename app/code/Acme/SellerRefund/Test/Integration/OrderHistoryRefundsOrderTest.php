<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Acme\SellerRefund\Block\Order\RefundHistory;
use Magento\Customer\Model\Session as CustomerSession;

/**
 * The active customer history lists individual refunds by descending refund ID.
 */
class OrderHistoryRefundsOrderTest extends DbTransactionTestCase
{
    public function testRefundedOrdersAreNewestFirst(): void
    {
        $seed = $this->get(SeedOrders::class);
        $processor = $this->get(RefundProcessor::class);
        $customerId = 0;
        foreach (['SR-ORD-1001', 'SR-ORD-1002'] as $incrementId) {
            $order = $seed->loadByIncrementId($incrementId);
            $customerId = (int) $order->getCustomerId();
            $processor->submit(
                (int) $order->getEntityId(),
                $seed->partialSubmission($seed->firstSellerLineId($order), '1')
            );
        }
        $this->get(CustomerSession::class)->setCustomerId($customerId);

        $collection = $this->create(RefundHistory::class)->getRefunds();
        self::assertNotNull($collection);
        $listed = array_map('intval', $collection->getColumnValues('entity_id'));
        $expected = $listed;
        rsort($expected, SORT_NUMERIC);

        self::assertGreaterThanOrEqual(3, count($listed));
        self::assertSame($expected, $listed, 'Customer refunds must be newest first.');
    }
}
