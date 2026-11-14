<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Exception\ValidationException;
use Acme\SellerRefund\Model\Submission\RefundSubmission;
use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Magento\Framework\App\ResourceConnection;

/**
 * Lost browser responses must not create another refund or initial outbox job.
 */
class SubmissionIdempotencyTest extends DbTransactionTestCase
{
    /**
     * @return void
     */
    public function testRetryReturnsOriginalRefundWithoutAdditionalRows(): void
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId('SR-ORD-1001');
        $line = $seed->firstSellerLineId($order);
        $processor = $this->get(RefundProcessor::class);
        $first = $processor->submit((int) $order->getEntityId(), $this->request([$line => '1']));
        $counts = $this->rowCounts();
        $retry = $processor->submit((int) $order->getEntityId(), $this->request([$line => '1.0000']));
        self::assertSame($first->getEntityId(), $retry->getEntityId());
        self::assertSame($first->getRefundNo(), $retry->getRefundNo());
        self::assertSame($counts, $this->rowCounts());
    }

    /**
     * @return void
     */
    public function testFullRefundRetrySucceedsAfterQuantityAndWindowAreExhausted(): void
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId('SR-ORD-1001');
        $full = $seed->fullSubmission($order);
        $request = new RefundSubmission($full->reasonCode, $full->qtyByItem, null, str_repeat('a', 32));
        $processor = $this->get(RefundProcessor::class);
        $first = $processor->submit((int) $order->getEntityId(), $request);
        $resource = $this->get(ResourceConnection::class);
        $this->connection()->update(
            $resource->getTableName('sales_order'),
            ['mp_delivered_at' => '2000-01-01 00:00:00'],
            ['entity_id = ?' => (int) $order->getEntityId()]
        );
        $retry = $processor->submit((int) $order->getEntityId(), $request);
        self::assertSame($first->getEntityId(), $retry->getEntityId());
    }

    /**
     * @return void
     */
    public function testChangedQuantityCannotReuseTheToken(): void
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId('SR-ORD-1001');
        $line = $seed->firstSellerLineId($order);
        $processor = $this->get(RefundProcessor::class);
        $processor->submit((int) $order->getEntityId(), $this->request([$line => '1']));
        $counts = $this->rowCounts();
        try {
            $processor->submit((int) $order->getEntityId(), $this->request([$line => '2']));
            self::fail('A token must not be reused with different quantities.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('different refund details', $exception->getMessage());
        }
        self::assertSame($counts, $this->rowCounts());
    }

    /**
     * @return void
     */
    public function testAnotherOrderCannotReuseTheToken(): void
    {
        $seed = $this->get(SeedOrders::class);
        $first = $seed->loadByIncrementId('SR-ORD-1001');
        $second = $seed->loadByIncrementId('SR-ORD-1002');
        $processor = $this->get(RefundProcessor::class);
        $processor->submit((int) $first->getEntityId(), $this->request([$seed->firstSellerLineId($first) => '1']));
        $this->expectException(ValidationException::class);
        $processor->submit((int) $second->getEntityId(), $this->request([$seed->firstSellerLineId($second) => '1']));
    }

    /**
     * @return void
     */
    public function testNewTokenAllowsAnotherIntentionalPartialRefund(): void
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId('SR-ORD-1001');
        $items = [$seed->firstSellerLineId($order) => '1'];
        $processor = $this->get(RefundProcessor::class);
        $first = $processor->submit((int) $order->getEntityId(), $this->request($items));
        $second = $processor->submit(
            (int) $order->getEntityId(),
            new RefundSubmission('defect', $items, null, str_repeat('b', 32))
        );
        self::assertNotSame($first->getEntityId(), $second->getEntityId());
    }

    /**
     * @param array $items
     * @return RefundSubmission
     */
    private function request(array $items): RefundSubmission
    {
        return new RefundSubmission('defect', $items, null, str_repeat('a', 32));
    }

    /**
     * @return array
     */
    private function rowCounts(): array
    {
        $resource = $this->get(ResourceConnection::class);
        $counts = [];
        foreach (['mp_refund', 'mp_refund_item', 'mp_refund_outbox'] as $table) {
            $counts[$table] = (int) $this->connection()->fetchOne(
                $this->connection()->select()->from($resource->getTableName($table), ['COUNT(*)'])
            );
        }

        return $counts;
    }
}
