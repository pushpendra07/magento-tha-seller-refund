<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Block\Adminhtml\Refund;

use Acme\SellerRefund\Model\RefundEligibility;
use Acme\SellerRefund\Model\ResourceModel\PriorRefundQuantity;
use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Source\RefundReason;
use Acme\SellerRefund\Model\Tax\LineTaxRate;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Locale\FormatInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Refund entry screen backing block. Exposes the seller order lines with their remaining
 * refundable quantity and the JS configuration the entry widget needs.
 */
class Form extends Template
{
    private ?OrderInterface $order = null;

    /**
     * @var array<int, string>|null order item id => quantity already refunded (read once)
     */
    private ?array $prior = null;

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SellerLineResolver $sellerLineResolver,
        private readonly PriorRefundQuantity $priorRefundQuantity,
        private readonly TaxCodeResolver $taxCodeResolver,
        private readonly LineTaxRate $lineTaxRate,
        private readonly FormKey $formKeyProvider,
        private readonly RefundReason $refundReason,
        private readonly RefundEligibility $eligibility,
        private readonly RefundTotalCalculator $calculator,
        private readonly FormatInterface $localeFormat,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Add a Back button to the page actions toolbar, as Magento's own admin pages do.
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        $toolbar = $this->getLayout()->getBlock('page.actions.toolbar');
        if ($toolbar) {
            $toolbar->addChild(
                'acme_refund_back',
                Button::class,
                [
                    'label' => __('Back'),
                    'class' => 'back',
                    'onclick' => sprintf("setLocation('%s')", $this->escapeJs($this->getBackUrl())),
                ]
            );
        }

        return parent::_prepareLayout();
    }

    /**
     * The order this refund is being created for.
     *
     * @return string
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $this->getOrderId()]);
    }

    public function getOrderId(): int
    {
        return (int) $this->getRequest()->getParam('order_id');
    }

    public function getOrder(): OrderInterface
    {
        if ($this->order === null) {
            $this->order = $this->orderRepository->get($this->getOrderId());
        }

        return $this->order;
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    public function getSellerLines(): array
    {
        $order = $this->getOrder();
        $prior = $this->getPriorRefunded();

        $lines = [];
        foreach ($this->sellerLineResolver->sellerLines($order) as $orderItemId => $item) {
            $ordered = sprintf('%.4F', (float) $item->getQtyOrdered());
            $refundedBefore = sprintf('%.4F', (float) ($prior[$orderItemId] ?? '0'));
            $remaining = bcsub($ordered, $refundedBefore, 4);
            $code = $this->taxCodeResolver->toBusinessCode((int) $item->getData('mp_tax_class'));

            $lines[] = [
                'order_item_id' => $orderItemId,
                'sku' => (string) $item->getSku(),
                'product_name' => (string) $item->getName(),
                'qty_ordered' => $ordered,
                'qty_refunded_before' => $refundedBefore,
                'qty_remaining' => $remaining,
                'unit_price' => sprintf('%.4F', (float) $item->getPrice()),
                'tax_code' => $code,
                'tax_rate' => $this->lineTaxRate->forItem($item),
            ];
        }

        return $lines;
    }

    /**
     * Reason options for the entry form, from the same source the server validates against.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function getReasonOptions(): array
    {
        return $this->refundReason->toOptionArray();
    }

    /**
     * Configuration for the refund-form widget, including Magento's price format for display.
     *
     * @return array<string, mixed>
     */
    public function getJsConfig(): array
    {
        $order = $this->getOrder();
        $currency = (string) $order->getOrderCurrencyCode();

        return [
            'calculateUrl' => $this->getUrl('acme_refund/refund/calculate'),
            'saveUrl' => $this->getUrl('acme_refund/refund/save'),
            'formKey' => $this->formKeyProvider->getFormKey(),
            'orderId' => (string) $this->getOrderId(),
            'refundNoPlaceholder' => 'SR-' . date('Ymd') . '-______',
            'currency' => $currency !== '' ? $currency : 'JPY',
            'priceFormat' => $this->localeFormat->getPriceFormat(null, $currency !== '' ? $currency : 'JPY'),
        ];
    }

    public function formatMoney(?string $amount, string $currency = 'JPY'): string
    {
        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }

    /**
     * Quantity already refunded per order item; one query per page.
     *
     * @return array<int, string>
     */
    private function getPriorRefunded(): array
    {
        if ($this->prior === null) {
            $this->prior = $this->priorRefundQuantity->sumRefundedByOrderItem($this->getOrderId());
        }

        return $this->prior;
    }

    /**
     * Order and customer facts for the information panel, from the order already loaded.
     *
     * @return array<string, string|int|null>
     */
    public function getOrderSummary(): array
    {
        $order = $this->getOrder();
        $timezone = new \DateTimeZone($this->_localeDate->getConfigTimezone());
        $deadline = $this->eligibility->deadline($order, $timezone);
        $now = new \DateTimeImmutable('now', $timezone);
        $deliveredAt = (string) $order->getData('mp_delivered_at');
        $name = trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname());

        return [
            'increment_id' => (string) $order->getIncrementId(),
            'order_url' => $this->getBackUrl(),
            'created_at' => $this->formatDate($order->getCreatedAt(), \IntlDateFormatter::MEDIUM, true),
            'status' => (string) $order->getStatusLabel(),
            'seller_order_id' => (string) $order->getData('mp_seller_order_id'),
            'delivered_at' => $deliveredAt !== '' ? $this->formatDate($deliveredAt, \IntlDateFormatter::MEDIUM) : '',
            'deadline' => $deadline ? $this->formatDate($deadline, \IntlDateFormatter::MEDIUM) : '',
            'days_left' => $deadline ? max(0, (int) $now->diff($deadline)->format('%r%a')) : null,
            'customer_name' => $name !== '' ? $name : (string) __('Guest'),
            'customer_email' => (string) $order->getCustomerEmail(),
        ];
    }

    /**
     * Pre-refund figures and an empty refund, from data already loaded (no extra request).
     *
     * @return RefundFigures
     */
    public function getInitialFigures(): RefundFigures
    {
        return $this->calculator->fromSelection(
            $this->getOrder(),
            [],
            $this->getPriorRefunded(),
            $this->_localeDate->date()
        );
    }

    /**
     * Amount in the order's currency format, as the admin order page shows it.
     *
     * @param string $amount
     * @return string
     */
    public function formatPrice(string $amount): string
    {
        return (string) $this->getOrder()->formatPriceTxt((float) $amount);
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

    /**
     * Tax rate as a percentage, for example "10%" for "0.1000".
     *
     * @param string $rate
     * @return string
     */
    public function formatRate(string $rate): string
    {
        return $this->formatQty(bcmul($rate, '100', 4)) . '%';
    }
}
