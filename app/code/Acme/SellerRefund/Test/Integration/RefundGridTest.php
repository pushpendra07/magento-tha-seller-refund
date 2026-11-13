<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Model\Outbox\Outbox;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;

/**
 * The Seller Refunds admin grid (acme_refund_listing). The old worklist loaded a fixed 50 rows
 * with no pager, so refund 51 onwards could not be opened from the list (FRD 10.2, 12.1).
 */
class RefundGridTest extends DbTransactionTestCase
{
    private const DATA_SOURCE = 'acme_refund_listing_data_source';
    private const EXTRA_REFUNDS = 25;

    public function testEveryRefundIsReachableThroughPaging(): void
    {
        $before = $this->grid()->getSize();
        $this->addRefunds(self::EXTRA_REFUNDS);

        $first = $this->grid()->setPageSize(20)->setCurPage(1);
        $second = $this->grid()->setPageSize(20)->setCurPage(2);
        $firstIds = $first->getAllIds();
        $firstPage = array_keys($first->getItems());
        $secondPage = array_keys($second->getItems());

        self::assertSame($before + self::EXTRA_REFUNDS, $first->getSize());
        self::assertCount(20, $firstPage);
        self::assertNotEmpty($secondPage, 'Page 2 must list the refunds beyond the first page.');
        self::assertSame([], array_intersect($firstPage, $secondPage), 'Page 2 must not repeat page 1.');
        self::assertCount($before + self::EXTRA_REFUNDS, $firstIds);
    }

    public function testRowsCarryTheJoinedOrderAndLineSummary(): void
    {
        foreach ($this->grid()->getItems() as $row) {
            self::assertNotSame('', (string) $row->getData('order_increment_id'));
            self::assertArrayHasKey('line_count', $row->getData());
            self::assertArrayHasKey('order_grand_total', $row->getData());
        }
    }

    /**
     * status also exists on sales_order; the filter must not fail as an ambiguous column.
     */
    public function testStatusFilterUsesTheRefundStatus(): void
    {
        $grid = $this->grid();
        $grid->addFieldToFilter('status', RefundInterface::STATUS_ERP_CONFIRMED);

        self::assertGreaterThanOrEqual(1, $grid->getSize());
        foreach ($grid->getItems() as $row) {
            self::assertSame(RefundInterface::STATUS_ERP_CONFIRMED, $row->getData('status'));
        }
    }

    /**
     * FRD 10.2: the worklist surfaces integration state. A refund whose ERP call will not succeed
     * on its own is flagged; one the outbox is still retrying, or that was replayed, is not.
     */
    public function testNeedsAttentionFlagsOnlyRefundsAnOperatorMustHandle(): void
    {
        [$rejected, $deadJob, $replayed, $retrying] = $this->addRefunds(4);
        $refunds = $this->get(ResourceConnection::class)->getTableName('mp_refund');
        $outbox = $this->get(ResourceConnection::class)->getTableName('mp_refund_outbox');
        $db = $this->connection();
        foreach ([$rejected, $deadJob, $replayed, $retrying] as $id) {
            $db->update($refunds, [
                'create_status' => RefundInterface::SUB_SUCCEEDED,
                'status_check_status' => RefundInterface::SUB_NOT_STARTED,
                'confirm_status' => RefundInterface::SUB_NOT_STARTED,
            ], ['entity_id = ?' => $id]);
        }
        $db->update(
            $refunds,
            ['status_check_status' => RefundInterface::SUB_BUSINESS_REJECTED],
            ['entity_id = ?' => $rejected]
        );
        $db->update(
            $refunds,
            ['status_check_status' => RefundInterface::SUB_RETRYABLE_ERROR],
            ['entity_id = ?' => $retrying]
        );
        $job = ['operation' => Outbox::OP_STATUS_CHECK, 'attempts' => 8, 'available_at' => '2026-01-01 00:00:00'];
        $db->insert($outbox, $job + ['refund_id' => $deadJob, 'state' => Outbox::STATE_DEAD]);
        $db->insert($outbox, $job + ['refund_id' => $replayed, 'state' => Outbox::STATE_DEAD]);
        $db->insert($outbox, $job + ['refund_id' => $replayed, 'state' => Outbox::STATE_PENDING]);

        $flagged = $this->grid()->addFieldToFilter('needs_attention', '1')->getAllIds();

        self::assertContains((string) $rejected, $flagged, 'A business reject needs an operator.');
        self::assertContains((string) $deadJob, $flagged, 'A dead-lettered job needs an operator.');
        self::assertNotContains((string) $replayed, $flagged, 'A replayed job is back in the queue.');
        self::assertNotContains((string) $retrying, $flagged, 'The outbox is still retrying this one.');
    }

    /**
     * Newest refunds first by primary key when the operator has not chosen a sort, which the
     * primary key serves without an extra index.
     */
    public function testDefaultOrderIsNewestFirstByPrimaryKey(): void
    {
        $this->addRefunds(3);
        $grid = $this->grid()->setPageSize(20)->setCurPage(1);

        $ids = array_map('intval', array_keys($grid->getItems()));
        self::assertStringContainsString('ORDER BY main_table.entity_id DESC', (string) $grid->getSelect());
        $sorted = $ids;
        rsort($sorted);
        self::assertSame($sorted, $ids);
    }

    /**
     * A column the operator sorts by replaces the default.
     */
    public function testOperatorSortReplacesTheDefault(): void
    {
        $grid = $this->grid();
        $grid->addOrder('grand_total', 'ASC');
        $grid->load();
        $select = (string) $grid->getSelect();

        self::assertStringContainsString('grand_total ASC', $select);
        self::assertStringNotContainsString('entity_id DESC', $select);
    }

    /**
     * A fresh grid collection, as the admin grid's data provider builds it.
     *
     * @return AbstractDb
     */
    private function grid(): AbstractDb
    {
        return $this->create(CollectionFactory::class)->getReport(self::DATA_SOURCE);
    }

    /**
     * Copy an existing refund row with new numbers; the test transaction rolls them back.
     *
     * @param int $count
     * @return int[] the new refund ids
     */
    private function addRefunds(int $count): array
    {
        $table = $this->get(ResourceConnection::class)->getTableName('mp_refund');
        $template = $this->connection()->fetchRow(
            $this->connection()->select()->from($table)->order('entity_id ASC')->limit(1)
        );
        self::assertNotEmpty($template, 'The seed refunds are missing.');
        unset($template['entity_id']);

        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $row = $template;
            $row['refund_no'] = sprintf('SR-TEST-%06d', $i);
            $this->connection()->insert($table, $row);
            $ids[] = (int) $this->connection()->lastInsertId($table);
        }

        return $ids;
    }
}
