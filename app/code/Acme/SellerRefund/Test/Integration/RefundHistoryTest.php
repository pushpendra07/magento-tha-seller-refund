<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Block\Order\RefundHistory;
use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Magento\Customer\Model\Session as CustomerSession;

/**
 * The "Refunded Orders" table under My Orders: the signed-in customer's visible refunds, newest
 * first, one page at a time.
 */
class RefundHistoryTest extends DbTransactionTestCase
{
    /**
     * Only the customer's refunds, no cancelled one, newest first, and page 2 continues page 1.
     *
     * @return void
     */
    public function testListsTheCustomersVisibleRefundsNewestFirstAndPages(): void
    {
        $seed = $this->get(SeedOrders::class);
        $processor = $this->get(RefundProcessor::class);
        $order = $seed->loadByIncrementId('SR-ORD-1001');
        $lineId = $seed->firstSellerLineId($order);
        $kept = $processor->submit((int) $order->getEntityId(), $seed->partialSubmission($lineId, '1'));
        $cancelled = $processor->submit((int) $order->getEntityId(), $seed->partialSubmission($lineId, '1'));
        $processor->cancel((int) $cancelled->getEntityId(), null);
        $customerId = (int) $order->getCustomerId();
        $this->get(CustomerSession::class)->setCustomerId($customerId);

        $all = $this->create(RefundHistory::class)->getRefunds();
        self::assertNotNull($all);
        $ids = array_map('intval', $all->getColumnValues('entity_id'));

        self::assertSame((int) $kept->getEntityId(), $ids[0], 'The newest refund comes first.');
        self::assertNotContains((int) $cancelled->getEntityId(), $ids, 'A cancelled refund is not listed.');
        $sorted = $ids;
        rsort($sorted);
        self::assertSame($sorted, $ids);
        foreach ($all as $refund) {
            self::assertNotContains($refund->getStatus(), [RefundInterface::STATUS_CANCELLED, 'failed', 'draft']);
            self::assertNotSame('', (string) $refund->getData('order_increment_id'));
        }
        self::assertGreaterThanOrEqual(3, count($ids), 'Seed refunds plus the kept one.');

        $page1 = $this->create(RefundHistory::class)->getRefunds()->setPageSize(2)->setCurPage(1);
        $page2 = $this->create(RefundHistory::class)->getRefunds()->setPageSize(2)->setCurPage(2);
        $firstPage = array_map('intval', $page1->getColumnValues('entity_id'));
        $secondPage = array_map('intval', $page2->getColumnValues('entity_id'));
        self::assertSame(array_slice($ids, 0, 2), $firstPage);
        self::assertSame(array_slice($ids, 2, 2), $secondPage);
    }

    /**
     * Signed-out visitors get no list.
     *
     * @return void
     */
    public function testNoListWithoutACustomer(): void
    {
        $this->get(CustomerSession::class)->setCustomerId(null);

        self::assertNull($this->create(RefundHistory::class)->getRefunds());
    }
}
