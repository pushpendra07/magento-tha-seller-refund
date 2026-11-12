<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Api\Data\RefundInterface;
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
     * @return void
     */
    private function addRefunds(int $count): void
    {
        $table = $this->get(ResourceConnection::class)->getTableName('mp_refund');
        $template = $this->connection()->fetchRow(
            $this->connection()->select()->from($table)->order('entity_id ASC')->limit(1)
        );
        self::assertNotEmpty($template, 'The seed refunds are missing.');
        unset($template['entity_id']);

        for ($i = 1; $i <= $count; $i++) {
            $row = $template;
            $row['refund_no'] = sprintf('SR-TEST-%06d', $i);
            $this->connection()->insert($table, $row);
        }
    }
}
