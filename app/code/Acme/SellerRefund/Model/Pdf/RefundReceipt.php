<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Source\RefundStatus;
use Acme\SellerRefund\Model\Total\RefundFigureLine;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Reissued receipt / qualified tax invoice for a refund. Every figure is taken from the
 * stored refund snapshot through the calculator, so the receipt agrees with the other
 * presentation surfaces and the downstream export.
 */
class RefundReceipt
{
    /**
     * @param RefundRepositoryInterface $refundRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param RefundTotalCalculator $calculator
     * @param TimezoneInterface $timezone
     * @param ReceiptDocumentFactory $documentFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param RefundStatus $refundStatus
     */
    public function __construct(
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RefundTotalCalculator $calculator,
        private readonly TimezoneInterface $timezone,
        private readonly ReceiptDocumentFactory $documentFactory,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly RefundStatus $refundStatus
    ) {
    }

    /**
     * View data for the receipt. This is the harness inspection point.
     *
     * @param RefundInterface $refund
     * @return array<string, mixed>
     */
    public function buildData(RefundInterface $refund): array
    {
        $items = $this->refundRepository->getItems((int) $refund->getEntityId());
        $order = $this->orderRepository->get($refund->getOrderId());
        $figures = $this->calculator->fromSnapshot($refund, $items, $order);

        $storeId = (int) $order->getStoreId();
        $prior = [];
        foreach ($items as $item) {
            $prior[$item->getOrderItemId()] = $item->getQtyRefundedBefore();
        }
        $data = [
            'refund_no' => $refund->getRefundNo(),
            'order_no' => (string) $order->getIncrementId(),
            'store_id' => $storeId,
            'status' => $refund->getStatus(),
            'status_label' => $this->refundStatus->getLabel($refund->getStatus()) ?? $refund->getStatus(),
            'currency' => $figures->currency,
            'is_partial' => $figures->isPartial(),
            'refund_date' => $this->timezone->scopeDate($storeId, $refund->getCreatedAt(), true)->format('Y-m-d'),
            'pre_refund' => [
                'subtotal' => $figures->preRefundSubtotal,
                'shipping' => $figures->preRefundShipping,
                'tax' => $figures->preRefundTax,
                'grand_total' => $figures->preRefundGrandTotal,
            ],
            'refund' => [
                'subtotal' => $figures->refundSubtotal,
                'shipping' => $figures->refundShipping,
                'tax' => $figures->refundTax,
                'grand_total' => $figures->refundGrandTotal,
            ],
            'lines' => array_map(
                static fn (RefundFigureLine $line): array => $line->toArray() + [
                    'refunded_before' => $prior[$line->orderItemId] ?? '0.0000',
                ],
                $figures->lines
            ),
            'tax_groups' => $this->taxGroups($figures->lines),
        ];
        foreach (['pre_refund', 'refund'] as $key) {
            $totals = $data[$key];
            $totals['ex_tax'] = bcadd($totals['subtotal'], $totals['shipping'], 4);
            $data['display'][$key] = $this->formatAmounts($totals, $storeId, $figures->currency);
        }
        foreach ($data['lines'] as $index => $line) {
            $data['display']['lines'][$index] = $this->formatAmounts(
                array_intersect_key($line, array_flip([
                    'unit_price', 'row_amount', 'shipping_amount', 'tax_amount', 'grand_total',
                ])),
                $storeId,
                $figures->currency
            );
        }
        foreach ($data['tax_groups'] as $index => $group) {
            $data['display']['tax_groups'][$index] = $this->formatAmounts(
                ['taxable' => $group['taxable'], 'tax' => $group['tax']],
                $storeId,
                $figures->currency
            );
        }

        return $data;
    }

    /**
     * Render the receipt as PDF bytes.
     *
     * @param RefundInterface $refund
     * @return string
     */
    public function render(RefundInterface $refund): string
    {
        return $this->documentFactory->create()->getPdf([$this->buildData($refund)])->render();
    }

    /**
     * Group immutable stored amounts by business tax code and rate.
     *
     * @param RefundFigureLine[] $lines
     * @return array<int, array<string, string>>
     */
    private function taxGroups(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $key = $line->taxCode . '|' . $line->taxRate;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'code' => $line->taxCode, 'rate' => $line->taxRate,
                    'taxable' => '0.0000', 'tax' => '0.0000',
                ];
            }
            $groups[$key]['taxable'] = bcadd(
                $groups[$key]['taxable'],
                bcadd($line->rowAmount, $line->shippingAmount, 4),
                4
            );
            $groups[$key]['tax'] = bcadd($groups[$key]['tax'], $line->taxAmount, 4);
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * Use Magento's store/currency presentation without changing snapshot amounts.
     *
     * @param array $amounts
     * @param int $storeId
     * @param string $currency
     * @return array<string, string>
     */
    private function formatAmounts(array $amounts, int $storeId, string $currency): array
    {
        $formatted = [];
        foreach ($amounts as $key => $amount) {
            $text = $this->priceCurrency->format(
                $amount,
                false,
                $currency === 'JPY' ? 0 : PriceCurrencyInterface::DEFAULT_PRECISION,
                $storeId,
                $currency
            );
            // Magento's bundled FreeSerif supports yen, but not its full-width locale variant.
            $formatted[$key] = str_replace("\u{FFE5}", "\u{00A5}", $text);
        }

        return $formatted;
    }
}
