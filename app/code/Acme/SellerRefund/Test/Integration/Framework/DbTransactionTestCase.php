<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration\Framework;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Base case that isolates every test in a database transaction.
 *
 * setUp() opens a transaction on the shared default connection; tearDown() rolls it back, so
 * nothing a test writes survives. RefundProcessor::submit() opens its own transaction and
 * commits it, but Magento's Pdo\Mysql adapter counts nested transactions: submit()'s
 * beginTransaction only raises the level and its commit only lowers it, so the outer rollback
 * here still undoes the refund, its items, events and outbox rows.
 */
abstract class DbTransactionTestCase extends AppTestCase
{
    private AdapterInterface $connection;

    /**
     * Delivery offsets in days, as the seeder defines them (seed/fixtures/orders.json).
     */
    private const SEED_DELIVERED_OFFSET_DAYS = [
        'SR-ORD-1001' => 5,
        'SR-ORD-1002' => 5,
        'SR-ORD-1003' => 6,
        'SR-ORD-1004' => 20,
        'SR-ORD-1005' => 5,
        'SR-ORD-1006' => 4,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $resource = $this->get(ResourceConnection::class);
        $this->connection = $resource->getConnection();
        $this->connection->beginTransaction();

        // Seed delivery dates are relative to when the image was seeded, so on an older stack
        // every order is outside the 14-day window (BR-02). Re-anchor them to now inside this
        // transaction; tearDown() rolls the change back.
        foreach (self::SEED_DELIVERED_OFFSET_DAYS as $incrementId => $days) {
            $this->connection->update(
                $resource->getTableName('sales_order'),
                ['mp_delivered_at' => new \Zend_Db_Expr(sprintf('NOW() - INTERVAL %d DAY', $days))],
                ['increment_id = ?' => $incrementId]
            );
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    protected function connection(): AdapterInterface
    {
        return $this->connection;
    }
}
