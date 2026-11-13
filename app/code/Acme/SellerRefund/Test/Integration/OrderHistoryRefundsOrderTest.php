<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Acme\SellerRefund\ViewModel\OrderHistoryRefunds;
use Magento\Customer\Model\Session as CustomerSession;

/**
 * The storefront "Refunded Orders" block lists orders newest first, like the My Orders list
 * above it, with a fixed order for orders placed in the same second.
 */
class OrderHistoryRefundsOrderTest extends DbTransactionTestCase
{
    /**
     * SR-ORD-1001 and SR-ORD-1002 share one timestamp; the higher id comes first, and every
     * entry is no older than the one after it.
     *
     * @return void
     */
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

        $listed = array_column($this->get(OrderHistoryRefunds::class)->getEntries(), 'increment_id');
        $keys = [];
        foreach ($listed as $incrementId) {
            $order = $seed->loadByIncrementId($incrementId);
            $keys[] = [(string) $order->getCreatedAt(), (int) $order->getEntityId()];
        }
        $expected = $keys;
        usort($expected, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        self::assertGreaterThanOrEqual(3, count($listed));
        self::assertSame($expected, $keys, 'Refunded orders must be newest first.');
        self::assertLessThan(
            array_search('SR-ORD-1001', $listed, true),
            array_search('SR-ORD-1002', $listed, true),
            'Orders placed in the same second keep a fixed order: higher id first.'
        );
    }
}
