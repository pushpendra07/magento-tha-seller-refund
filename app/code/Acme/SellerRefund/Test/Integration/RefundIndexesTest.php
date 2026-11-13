<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Magento\Framework\App\ResourceConnection;

/**
 * Indexes the refund queries depend on.
 *
 * The outbox lookups (hasDead, hasPending, the grid's Needs Attention check) filter by refund,
 * operation and state. The Seller Refunds grid sorts by the primary key by default, which needs
 * no extra index (FRD 10.2: first page within 1.5 s).
 */
class RefundIndexesTest extends DbTransactionTestCase
{
    /**
     * The outbox lookups have a declared index.
     *
     * @return void
     */
    public function testOutboxLookupIndexExists(): void
    {
        self::assertSame(
            ['refund_id', 'operation', 'state'],
            $this->indexColumns('mp_refund_outbox', 'MP_REFUND_OUTBOX_REFUND_ID_OPERATION_STATE')
        );
    }

    /**
     * The undeclared index the database created for the foreign key is gone; the declared
     * composite index serves the foreign key instead.
     *
     * @return void
     */
    public function testNoUndeclaredOutboxIndexRemains(): void
    {
        $indexes = array_change_key_case(
            $this->connection()->getIndexList($this->table('mp_refund_outbox')),
            CASE_UPPER
        );

        self::assertArrayNotHasKey('MP_REFUND_OUTBOX_REFUND_ID_MP_REFUND_ENTITY_ID', $indexes);
    }

    /**
     * Columns of a named index, in order.
     *
     * @param string $table
     * @param string $indexName
     * @return string[]
     */
    private function indexColumns(string $table, string $indexName): array
    {
        $indexes = array_change_key_case($this->connection()->getIndexList($this->table($table)), CASE_UPPER);
        self::assertArrayHasKey($indexName, $indexes, sprintf('Index %s is missing on %s.', $indexName, $table));

        return array_values($indexes[$indexName]['COLUMNS_LIST']);
    }

    /**
     * Prefixed table name.
     *
     * @param string $table
     * @return string
     */
    private function table(string $table): string
    {
        return $this->get(ResourceConnection::class)->getTableName($table);
    }
}
