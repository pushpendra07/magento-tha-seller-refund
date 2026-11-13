<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Presentation;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\ResourceModel\Refund\CollectionFactory as RefundCollectionFactory;

/**
 * Refunds a customer may see on the storefront (order details and order history, FRD 10.4).
 *
 * A draft was never saved as a refund, and a failed or cancelled refund will never be paid,
 * so showing any of them as "Refund ... JPY n" would tell the customer money is coming back
 * when it is not. Every other status is a refund the store has registered.
 */
class CustomerVisibleRefunds
{
    /**
     * Statuses never shown to the customer.
     */
    public const HIDDEN_STATUSES = [
        RefundInterface::STATUS_DRAFT,
        RefundInterface::STATUS_FAILED,
        RefundInterface::STATUS_CANCELLED,
    ];

    /**
     * @param RefundRepositoryInterface $refundRepository
     * @param RefundCollectionFactory $refundCollectionFactory
     */
    public function __construct(
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly RefundCollectionFactory $refundCollectionFactory
    ) {
    }

    /**
     * Whether the order has any refund the customer may see: one indexed row, not the full list.
     *
     * @param int $orderId
     * @return bool
     */
    public function hasAny(int $orderId): bool
    {
        $collection = $this->refundCollectionFactory->create()
            ->addFieldToSelect(RefundInterface::ENTITY_ID)
            ->addFieldToFilter(RefundInterface::ORDER_ID, $orderId)
            ->addFieldToFilter(RefundInterface::STATUS, ['nin' => self::HIDDEN_STATUSES])
            ->setPageSize(1);

        return count($collection->getItems()) > 0;
    }

    /**
     * Refunds of the order that the customer should see, oldest first.
     *
     * @param int $orderId
     * @return RefundInterface[]
     */
    public function forOrder(int $orderId): array
    {
        return array_values(array_filter(
            $this->refundRepository->getByOrderId($orderId),
            static fn (RefundInterface $refund): bool => !in_array(
                $refund->getStatus(),
                self::HIDDEN_STATUSES,
                true
            )
        ));
    }
}
