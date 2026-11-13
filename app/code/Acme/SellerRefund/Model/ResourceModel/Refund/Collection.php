<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\ResourceModel\Refund;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Model\Outbox\Outbox;
use Acme\SellerRefund\Model\Refund as RefundModel;
use Acme\SellerRefund\Model\ResourceModel\Refund as RefundResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct(): void
    {
        $this->_init(RefundModel::class, RefundResource::class);
    }

    public function addStatusFilter(string $status): self
    {
        $this->addFieldToFilter(RefundInterface::STATUS, $status);

        return $this;
    }

    /**
     * Keep only refunds with no queued outbox job and no dead-lettered job for $deadOperation.
     *
     * Done in SQL, before the page limit, so refunds that are already waiting or need an operator
     * cannot fill every page and hide the ones that need a job (served by the outbox
     * (refund_id, operation, state) index).
     *
     * @param string $deadOperation
     * @return $this
     */
    public function addWithoutOutboxWorkFilter(string $deadOperation): self
    {
        $connection = $this->getConnection();
        $work = $connection->select()
            ->from(['outbox' => $this->getTable('mp_refund_outbox')], [new \Zend_Db_Expr('1')])
            ->where('outbox.refund_id = main_table.entity_id')
            ->where(
                $connection->quoteInto('outbox.state = ?', Outbox::STATE_PENDING) . ' OR ('
                . $connection->quoteInto('outbox.operation = ?', $deadOperation) . ' AND '
                . $connection->quoteInto('outbox.state = ?', Outbox::STATE_DEAD) . ')'
            );
        $this->getSelect()->where('NOT EXISTS (?)', $work);

        return $this;
    }

    public function addCreatedBeforeFilter(string $createdBefore): self
    {
        $this->addFieldToFilter(RefundInterface::CREATED_AT, ['lt' => $createdBefore]);

        return $this;
    }
}
