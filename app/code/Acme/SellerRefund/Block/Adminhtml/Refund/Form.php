<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Block\Adminhtml\Refund;

use Acme\SellerRefund\Model\RefundEligibility;
use Acme\SellerRefund\Model\ResourceModel\PriorRefundQuantity;
use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Source\RefundReason;
use Acme\SellerRefund\Model\Tax\LineTaxRate;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
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

    /** @var string|null */
    private ?string $submissionToken = null;

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
            'submissionToken' => $this->submissionToken ??= bin2hex(random_bytes(16)),
            'orderId' => (string) $this->getOrderId(),
            'currency' => $currency !== '' ? $currency : 'JPY',
            'priceFormat' => $this->localeFormat->getPriceFormat(null, $currency !== '' ? $currency : 'JPY'),
        ];
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
     * Order details shown above the form, from the order already loaded (no extra query).
     *
     * @return array<string, string|int|null>
     */
    public function getOrderSummary(): array
    {
        $order = $this->getOrder();
        $timezone = new \DateTimeZone($this->_localeDate->getConfigTimezone());
        $deadline = $this->eligibility->deadline($order, $timezone);
        $now = new \DateTimeImmutable('now', $timezone);
        $name = trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname());

        return [
            'increment_id' => (string) $order->getIncrementId(),
            'order_url' => $this->getBackUrl(),
            'created_at' => $this->formatDate($order->getCreatedAt(), \IntlDateFormatter::MEDIUM),
            'customer_name' => $name !== '' ? $name : (string) __('Guest'),
            'customer_email' => (string) $order->getCustomerEmail(),
            'deadline' => $deadline ? $this->formatDate($deadline, \IntlDateFormatter::MEDIUM) : '',
            'days_left' => $deadline ? max(0, (int) $now->diff($deadline)->format('%r%a')) : null,
        ];
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
