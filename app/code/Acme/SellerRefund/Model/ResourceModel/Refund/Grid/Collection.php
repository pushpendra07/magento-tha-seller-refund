<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\ResourceModel\Refund\Grid;

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
        $this->addFilterToMap('order_increment_id', 'so.increment_id');
        $this->addFilterToMap('order_grand_total', 'so.grand_total');

        return $this;
    }
}
