<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Config;
use Acme\SellerRefund\Model\Outbox\Dispatcher;
use Acme\SellerRefund\Model\Outbox\Outbox;
use Acme\SellerRefund\Model\Reconciler;
use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\ErpStub;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use DateTimeImmutable;
use Magento\Framework\App\ResourceConnection;

/**
 * Outbox recovery paths: the reconciler must not resurrect dead-lettered jobs, and the
 * immediate attempt made by the admin save must process only the refund just saved.
 */
class OutboxRecoveryTest extends DbTransactionTestCase
{
    protected function tearDown(): void
    {
        $this->get(ErpStub::class)->reset();
        parent::tearDown();
    }

    /**
     * A status check that was dead-lettered waits for an operator replay. Before the fix the
     * reconciler re-queued it on every sweep, retrying a permanently failing call forever.
     */
    public function testReconcilerDoesNotRequeueADeadStatusCheck(): void
    {
        // The seed leaves SR-ORD-1006's refund in cash_refunded.
        $refundId = $this->refundIdForOrder('SR-ORD-1006');
        $connection = $this->connection();
        $table = $this->get(ResourceConnection::class)->getTableName('mp_refund_outbox');
        $connection->delete($table, ['refund_id = ?' => $refundId]);
        $connection->insert($table, [
            'refund_id' => $refundId,
            'operation' => Outbox::OP_STATUS_CHECK,
            'state' => Outbox::STATE_DEAD,
            'attempts' => 8,
            'available_at' => new \Zend_Db_Expr('NOW()'),
        ]);

        $this->get(Reconciler::class)->run(new DateTimeImmutable('+1 day'), 500);

        self::assertFalse(
            $this->get(Outbox::class)->hasPending($refundId),
            'A dead-lettered status check must not be re-queued by the reconciler.'
        );
    }

    /**
     * Save makes one best-effort Create attempt. It must be for the refund just saved, not the
     * oldest pending job in the queue, which may belong to another refund.
     */
    public function testScopedRunProcessesOnlyTheGivenRefund(): void
    {
        $stub = $this->get(ErpStub::class);
        $stub->reset();
        $stub->setFailmode('ok');
        $seed = $this->get(SeedOrders::class);
        $processor = $this->get(RefundProcessor::class);

        $older = $this->submitOne($seed, $processor, 'SR-ORD-1001');
        $newer = $this->submitOne($seed, $processor, 'SR-ORD-1002');

        $processed = $this->get(Dispatcher::class)->run(Outbox::OP_CREATE, 1, $newer);

        $repository = $this->get(RefundRepositoryInterface::class);
        self::assertSame(1, $processed);
        self::assertSame(RefundInterface::STATUS_CASH_REFUND_PENDING, $repository->getById($newer)->getStatus());
        self::assertSame(RefundInterface::STATUS_CALCULATED, $repository->getById($older)->getStatus());
        self::assertTrue($this->get(Outbox::class)->hasPending($older), 'The older job must still be queued.');
    }

    /**
     * After the last allowed attempt the refund must say the retries are exhausted, not
     * "Retrying"; a replay queues the call again and the next run can succeed (FRD 14).
     */
    public function testExhaustedRetriesAreShownAndAReplayRecovers(): void
    {
        $stub = $this->get(ErpStub::class);
        $stub->reset();
        $stub->setFailmode('server_error_5xx');
        $processor = $this->get(RefundProcessor::class);
        $dispatcher = $this->get(Dispatcher::class);
        $repository = $this->get(RefundRepositoryInterface::class);
        $refundId = $this->submitOne($this->get(SeedOrders::class), $processor, 'SR-ORD-1001');
        $outbox = $this->get(ResourceConnection::class)->getTableName('mp_refund_outbox');

        $maxAttempts = $this->get(Config::class)->maxAttempts();
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->connection()->update(
                $outbox,
                ['available_at' => '2000-01-01 00:00:00'],
                ['refund_id = ?' => $refundId, 'state = ?' => Outbox::STATE_PENDING]
            );
            $dispatcher->run(Outbox::OP_CREATE, 1, $refundId);
        }

        $refund = $repository->getById($refundId);
        self::assertSame(RefundInterface::STATUS_CALCULATED, $refund->getStatus());
        self::assertSame(RefundInterface::SUB_RETRIES_EXHAUSTED, $refund->getCreateStatus());
        self::assertTrue($this->get(Outbox::class)->hasDead($refundId, Outbox::OP_CREATE));

        $processor->replay($refundId, null);
        self::assertSame(RefundInterface::SUB_PENDING, $repository->getById($refundId)->getCreateStatus());

        $stub->setFailmode('ok');
        $dispatcher->run(Outbox::OP_CREATE, 1, $refundId);
        $refund = $repository->getById($refundId);
        self::assertSame(RefundInterface::STATUS_CASH_REFUND_PENDING, $refund->getStatus());
        self::assertSame(RefundInterface::SUB_SUCCEEDED, $refund->getCreateStatus());
    }

    /**
     * Submit a one-unit refund on the first seller line of a seed order.
     *
     * @param SeedOrders $seed
     * @param RefundProcessor $processor
     * @param string $incrementId
     * @return int
     */
    private function submitOne(SeedOrders $seed, RefundProcessor $processor, string $incrementId): int
    {
        $order = $seed->loadByIncrementId($incrementId);
        $refund = $processor->submit(
            (int) $order->getEntityId(),
            $seed->partialSubmission($seed->firstSellerLineId($order), '1')
        );

        return (int) $refund->getEntityId();
    }

    /**
     * Find the seeded refund for an order.
     *
     * @param string $incrementId
     * @return int
     */
    private function refundIdForOrder(string $incrementId): int
    {
        $order = $this->get(SeedOrders::class)->loadByIncrementId($incrementId);
        $resource = $this->get(ResourceConnection::class);
        $refundId = (int) $this->connection()->fetchOne(
            $this->connection()->select()
                ->from($resource->getTableName('mp_refund'), ['entity_id'])
                ->where('order_id = ?', (int) $order->getEntityId())
                ->where('status = ?', RefundInterface::STATUS_CASH_REFUNDED)
                ->limit(1)
        );
        self::assertGreaterThan(0, $refundId, sprintf('No cash_refunded seed refund on %s.', $incrementId));

        return $refundId;
    }
}
