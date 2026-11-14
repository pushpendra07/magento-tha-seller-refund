<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Model\Outbox\Outbox;
use Acme\SellerRefund\Model\ResourceModel\Refund\CollectionFactory as RefundCollectionFactory;
use DateTimeInterface;

/**
 * Safety-net sweep for the Read Refund Status step: finds refunds sitting in cash_refunded
 * and enqueues a status check for any that has no pending outbox job. The status filter and
 * created_at bound are served by the MP_REFUND_STATUS_CREATED_AT index.
 */
class Reconciler
{
    public function __construct(
        private readonly RefundCollectionFactory $collectionFactory,
        private readonly Outbox $outbox
    ) {
    }

    public function run(DateTimeInterface $now, int $batch): int
    {
        $collection = $this->collectionFactory->create();
        // Refunds that already have a queued job, or a dead-lettered status check waiting for an
        // operator replay, are excluded in SQL before the page limit. Filtering them after the
        // limit let a full page of such refunds hide every later one that needed a job.
        $collection->addStatusFilter(RefundInterface::STATUS_CASH_REFUNDED)
            ->addCreatedBeforeFilter($now->format('Y-m-d H:i:s'))
            ->addWithoutOutboxWorkFilter(Outbox::OP_STATUS_CHECK)
            ->setOrder(RefundInterface::ENTITY_ID, 'ASC')
            ->setPageSize($batch)
            ->setCurPage(1);

        $enqueued = 0;
        foreach ($collection as $refund) {
            $this->outbox->enqueue((int) $refund->getEntityId(), Outbox::OP_STATUS_CHECK);
            $enqueued++;
        }

        return $enqueued;
    }
}
