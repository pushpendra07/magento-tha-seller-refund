<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Exception\NotEligibleException;
use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Magento\Framework\App\ResourceConnection;

/**
 * BR-01/BR-02 are enforced by the submission service itself. The admin hides the Refund button
 * for ineligible orders, but a direct request to the save endpoint must be refused as well.
 */
class EligibilityEnforcedTest extends DbTransactionTestCase
{
    public function testSubmitOnAnExpiredOrderIsRefusedAndNothingIsWritten(): void
    {
        // SR-ORD-1004 was delivered 20 days ago, outside the 14-day window.
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId('SR-ORD-1004');
        $orderId = (int) $order->getEntityId();
        $table = $this->get(ResourceConnection::class)->getTableName('mp_refund');
        $before = (int) $this->connection()->fetchOne(
            $this->connection()->select()->from($table, ['COUNT(*)'])->where('order_id = ?', $orderId)
        );

        try {
            $this->get(RefundProcessor::class)->submit(
                $orderId,
                $seed->partialSubmission($seed->firstSellerLineId($order), '1')
            );
            self::fail('A refund on an order outside the refund window must be refused.');
        } catch (NotEligibleException $e) {
            self::assertStringContainsString('window_expired', $e->getMessage());
        }

        $after = (int) $this->connection()->fetchOne(
            $this->connection()->select()->from($table, ['COUNT(*)'])->where('order_id = ?', $orderId)
        );
        self::assertSame($before, $after, 'No refund may be written for an ineligible order.');
    }
}
