<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Block\Order;

use Acme\SellerRefund\Model\Presentation\CustomerVisibleRefunds;
use Acme\SellerRefund\Model\ResourceModel\RefundItem\CollectionFactory as RefundItemCollectionFactory;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Storefront order-details refund section. Shows the original pre-refund total and a distinct
 * refund section beneath it, all derived from the stored refund snapshot through the
 * calculator so the figures match every other surface.
 */
class RefundDetails extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly RefundTotalCalculator $calculator,
        private readonly CustomerVisibleRefunds $visibleRefunds,
        private readonly RefundItemCollectionFactory $itemCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrder(): ?OrderInterface
    {
        $order = $this->registry->registry('current_order');

        return $order instanceof OrderInterface ? $order : null;
    }

    /**
     * @return array<int, array{refund: \Acme\SellerRefund\Api\Data\RefundInterface, figures: \Acme\SellerRefund\Model\Total\RefundFigures}>
     */
    public function getRefundViews(): array
    {
        $order = $this->getOrder();
        if ($order === null) {
            return [];
        }

        $refunds = $this->visibleRefunds->forOrder((int) $order->getEntityId());
        $itemsByRefund = $this->itemsByRefund($refunds);

        $views = [];
        foreach ($refunds as $refund) {
            $items = $itemsByRefund[(int) $refund->getEntityId()] ?? [];
            $views[] = [
                'refund' => $refund,
                'figures' => $this->calculator->fromSnapshot($refund, $items, $order),
            ];
        }

        return $views;
    }

    /**
     * Items of all the given refunds in one query, grouped by refund (oldest item first).
     *
     * @param \Acme\SellerRefund\Api\Data\RefundInterface[] $refunds
     * @return \Acme\SellerRefund\Api\Data\RefundItemInterface[][] refund id => items
     */
    private function itemsByRefund(array $refunds): array
    {
        $refundIds = array_map(
            static fn ($refund): int => (int) $refund->getEntityId(),
            $refunds
        );
        if ($refundIds === []) {
            return [];
        }

        $items = $this->itemCollectionFactory->create()
            ->addFieldToFilter('refund_id', ['in' => $refundIds])
            ->setOrder('entity_id', 'ASC');
        $grouped = [];
        foreach ($items as $item) {
            $grouped[(int) $item->getRefundId()][] = $item;
        }

        return $grouped;
    }

    public function formatMoney(?string $amount, string $currency): string
    {
        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }

    /**
     * Amount in the order's own price format, as plain text for escaping in the template.
     *
     * @param string $amount
     * @return string
     */
    public function formatOrderPrice(string $amount): string
    {
        $order = $this->getOrder();

        return $order !== null
            ? (string) $order->formatPriceTxt((float) $amount)
            : $this->formatMoney($amount, 'JPY');
    }

    /**
     * Quantity without trailing decimals, for example "2" for "2.0000".
     *
     * @param string $qty
     * @return string
     */
    public function formatQty(string $qty): string
    {
        return rtrim(rtrim(sprintf('%.4F', (float) $qty), '0'), '.');
    }

    public function renderRefundDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->_localeDate->formatDate($value, \IntlDateFormatter::MEDIUM, false);
    }
}
