<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\ResourceModel\Refund\Grid;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Model\Outbox\Outbox;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Data source for the Seller Refunds admin grid (acme_refund_listing).
 *
 * One row per refund with the order number, order total and a grouped line summary joined in,
 * so the grid never loads an order or its lines per row (FRD 12.1). Paging, sorting, filters
 * and keyword search are applied by Magento's standard UI data provider.
 */
class Collection extends SearchResult
{
    /**
     * Join the order summary and the per-refund line summary.
     *
     * @return $this
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        $connection = $this->getConnection();
        $lineSummary = $connection->select()
            ->from(
                $this->getTable('mp_refund_item'),
                [
                    'refund_id',
                    'line_count' => new \Zend_Db_Expr('COUNT(*)'),
                    'qty_refund_total' => new \Zend_Db_Expr('SUM(qty_refund)'),
                ]
            )
            ->group('refund_id');

        $this->getSelect()
            ->joinLeft(
                ['so' => $this->getTable('sales_order')],
                'main_table.order_id = so.entity_id',
                [
                    'order_increment_id' => 'so.increment_id',
                    'order_grand_total' => 'so.grand_total',
                ]
            )
            ->joinLeft(
                ['line_summary' => $lineSummary],
                'main_table.entity_id = line_summary.refund_id',
                ['line_count', 'qty_refund_total']
            );

        // sales_order has its own status, created_at, grand_total and entity_id columns.
        foreach (['entity_id', 'status', 'created_at', 'grand_total', 'currency_code'] as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
        $needsAttention = $this->needsAttentionExpression();
        $this->getSelect()->columns(['needs_attention' => new \Zend_Db_Expr($needsAttention)]);
        $this->addFilterToMap('needs_attention', new \Zend_Db_Expr($needsAttention));
        foreach (['create_status', 'status_check_status', 'confirm_status'] as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
        $this->addFilterToMap('order_increment_id', 'so.increment_id');
        $this->addFilterToMap('order_grand_total', 'so.grand_total');

        return $this;
    }

    /**
     * Newest refunds first unless the operator chose a sort.
     *
     * The primary key follows insertion order, so this matches created_at order without an extra
     * index on created_at. A column the operator sorts by is used as given.
     *
     * @return $this
     */
    protected function _renderOrders()
    {
        if (empty($this->_orders)) {
            $this->setOrder('main_table.entity_id', self::SORT_ORDER_DESC);
        }

        return parent::_renderOrders();
    }

    /**
     * SQL for "needs an operator": an ERP call that will not succeed on its own.
     *
     * That is a business reject, a 409 conflict or a request that failed the local check on any
     * of the three ERP calls, or an outbox job that was dead-lettered and has not been replayed
     * since (a replay adds a newer job for the same operation). A retryable error alone does not
     * count: the outbox is still retrying it.
     *
     * @return string
     */
    private function needsAttentionExpression(): string
    {
        $connection = $this->getConnection();
        $outbox = $connection->quoteIdentifier($this->getTable('mp_refund_outbox'));
        $stuck = $connection->quoteInto('IN (?)', [
            RefundInterface::SUB_BUSINESS_REJECTED,
            RefundInterface::SUB_CONFLICT,
            RefundInterface::SUB_INVALID_PAYLOAD,
            RefundInterface::SUB_RETRIES_EXHAUSTED,
        ]);
        $dead = $connection->quote(Outbox::STATE_DEAD);

        return 'CASE WHEN main_table.create_status ' . $stuck
            . ' OR main_table.status_check_status ' . $stuck
            . ' OR main_table.confirm_status ' . $stuck
            . ' OR EXISTS (SELECT 1 FROM ' . $outbox . ' AS dead_job'
            . ' WHERE dead_job.refund_id = main_table.entity_id AND dead_job.state = ' . $dead
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $outbox . ' AS later_job'
            . ' WHERE later_job.refund_id = dead_job.refund_id AND later_job.operation = dead_job.operation'
            . ' AND later_job.entity_id > dead_job.entity_id))'
            . ' THEN 1 ELSE 0 END';
    }
}
