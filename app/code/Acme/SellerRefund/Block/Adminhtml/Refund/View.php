<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Block\Adminhtml\Refund;

use Acme\SellerRefund\Api\Data\RefundEventInterface;
use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\RefundStateMachine;
use Acme\SellerRefund\Model\Source\RefundReason;
use Acme\SellerRefund\Model\ResourceModel\RefundEvent\CollectionFactory as EventCollectionFactory;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Framework\AuthorizationInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Refund detail backing block: the stored refund, its figures rebuilt from the snapshot,
 * and the append-only audit trail.
 */
class View extends Template
{
    /**
     * Operator-facing labels for the main lifecycle status (FRD section 7).
     */
    private const STATUS_LABELS = [
        RefundInterface::STATUS_DRAFT => 'Draft',
        RefundInterface::STATUS_CALCULATED => 'Saved, waiting for the ERP',
        RefundInterface::STATUS_CASH_REFUND_PENDING => 'Waiting for the cash refund',
        RefundInterface::STATUS_CASH_REFUNDED => 'Cash refunded, confirming with the ERP',
        RefundInterface::STATUS_ERP_CONFIRM_PENDING => 'Confirming with the ERP',
        RefundInterface::STATUS_ERP_CONFIRMED => 'Completed',
        RefundInterface::STATUS_FAILED => 'Rejected by the ERP',
        RefundInterface::STATUS_CANCELLED => 'Cancelled',
    ];

    /**
     * Operator-facing labels for the per-operation sub-statuses.
     */
    private const SUB_STATUS_LABELS = [
        RefundInterface::SUB_NOT_STARTED => 'Not started',
        RefundInterface::SUB_PENDING => 'In progress',
        RefundInterface::SUB_SUCCEEDED => 'Succeeded',
        RefundInterface::SUB_RETRYABLE_ERROR => 'Retrying',
        RefundInterface::SUB_BUSINESS_REJECTED => 'Rejected',
        RefundInterface::SUB_CONFLICT => 'Conflict, needs reconciliation',
    ];

    /**
     * Magento admin grid severity classes, so status reads at a glance.
     */
    private const SEVERITY = [
        RefundInterface::STATUS_ERP_CONFIRMED => 'grid-severity-notice',
        RefundInterface::SUB_SUCCEEDED => 'grid-severity-notice',
        RefundInterface::SUB_RETRYABLE_ERROR => 'grid-severity-major',
        RefundInterface::STATUS_FAILED => 'grid-severity-critical',
        RefundInterface::STATUS_CANCELLED => 'grid-severity-critical',
        RefundInterface::SUB_BUSINESS_REJECTED => 'grid-severity-critical',
        RefundInterface::SUB_CONFLICT => 'grid-severity-critical',
    ];

    private ?RefundInterface $refund = null;

    /**
     * @var OrderInterface|null
     */
    private ?OrderInterface $order = null;

    public function __construct(
        Context $context,
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RefundTotalCalculator $calculator,
        private readonly EventCollectionFactory $eventCollectionFactory,
        private readonly AuthorizationInterface $authorization,
        private readonly RefundStateMachine $stateMachine,
        private readonly RefundReason $refundReason,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getRefund(): RefundInterface
    {
        if ($this->refund === null) {
            $this->refund = $this->refundRepository->getById($this->getRefundId());
        }

        return $this->refund;
    }

    public function getRefundId(): int
    {
        return (int) $this->getRequest()->getParam('refund_id');
    }

    public function getFigures(): RefundFigures
    {
        $refund = $this->getRefund();
        $items = $this->refundRepository->getItems((int) $refund->getEntityId());

        return $this->calculator->fromSnapshot($refund, $items, $this->getOrder());
    }

    /**
     * The order this refund belongs to.
     *
     * @return OrderInterface
     */
    public function getOrder(): OrderInterface
    {
        if ($this->order === null) {
            $this->order = $this->orderRepository->get($this->getRefund()->getOrderId());
        }

        return $this->order;
    }

    /**
     * Admin URL of the order this refund belongs to.
     *
     * @return string
     */
    public function getOrderUrl(): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $this->getRefund()->getOrderId()]);
    }

    /**
     * Readable label for a main status or sub-status code.
     *
     * @param string|null $code
     * @return string
     */
    public function getStatusLabel(?string $code): string
    {
        $code = (string) $code;
        $label = self::STATUS_LABELS[$code] ?? self::SUB_STATUS_LABELS[$code] ?? $this->humanize($code);

        return (string) __($label);
    }

    /**
     * Admin grid severity class for a status code, or an empty string for neutral states.
     *
     * @param string|null $code
     * @return string
     */
    public function getStatusSeverity(?string $code): string
    {
        return self::SEVERITY[(string) $code] ?? '';
    }

    /**
     * Readable label for the refund reason code (FRD section 13).
     *
     * @return string
     */
    public function getReasonLabel(): string
    {
        $code = $this->getRefund()->getReasonCode();
        foreach ($this->refundReason->toOptionArray() as $option) {
            if ($option['value'] === $code) {
                return (string) $option['label'];
            }
        }

        return $code;
    }

    /**
     * Readable name for an audit event type, for example "erp_create" as "ERP create".
     *
     * @param string|null $code
     * @return string
     */
    public function humanize(?string $code): string
    {
        $text = ucfirst(str_replace('_', ' ', (string) $code));

        return (string) preg_replace('/\berp\b/i', 'ERP', $text);
    }

    /**
     * Pretty-print a stored JSON payload for the log popup; other text is returned unchanged.
     *
     * @param string|null $payload
     * @return string
     */
    public function formatPayload(?string $payload): string
    {
        if ($payload === null || $payload === '') {
            return '';
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $payload;
        }

        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Add the standard header buttons: Back, View Order and Download Receipt.
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        $toolbar = $this->getLayout()->getBlock('page.actions.toolbar');
        if ($toolbar) {
            $toolbar->addChild('acme_refund_back', Button::class, [
                'label' => __('Back'),
                'class' => 'back',
                'onclick' => $this->setLocation($this->getUrl('acme_refund/refund/index')),
            ]);
            $toolbar->addChild('acme_refund_view_order', Button::class, [
                'label' => __('View Order'),
                'class' => 'action-secondary',
                'onclick' => $this->setLocation($this->getOrderUrl()),
            ]);
            if ($this->canDownloadReceipt()) {
                $toolbar->addChild('acme_refund_receipt', Button::class, [
                    'label' => __('Download Receipt'),
                    'class' => 'action-secondary',
                    'onclick' => $this->setLocation($this->getActionUrl('receipt')),
                ]);
            }
        }

        return parent::_prepareLayout();
    }

    /**
     * Button onclick that navigates to a URL.
     *
     * @param string $url
     * @return string
     */
    private function setLocation(string $url): string
    {
        return sprintf("setLocation('%s')", $this->escapeJs($url));
    }

    /**
     * @return RefundEventInterface[]
     */
    public function getEvents(): array
    {
        return $this->eventCollectionFactory->create()
            ->addRefundFilter($this->getRefundId())
            ->getItems();
    }

    public function canSeeRawPayload(): bool
    {
        return $this->authorization->isAllowed('Acme_SellerRefund::audit');
    }

    /**
     * Refund Complete is offered while the credit note is open and the cash refund is unregistered (§10.3).
     *
     * @return bool
     */
    public function canRegisterCashRefund(): bool
    {
        return $this->authorization->isAllowed('Acme_SellerRefund::cash_register')
            && $this->getRefund()->getStatus() === RefundInterface::STATUS_CASH_REFUND_PENDING;
    }

    /**
     * Replay is offered for the states that have a retriable ERP operation (§9.6).
     *
     * @return bool
     */
    public function canRetry(): bool
    {
        return $this->authorization->isAllowed('Acme_SellerRefund::retry')
            && in_array(
                $this->getRefund()->getStatus(),
                [
                    RefundInterface::STATUS_CALCULATED,
                    RefundInterface::STATUS_CASH_REFUNDED,
                    RefundInterface::STATUS_ERP_CONFIRM_PENDING,
                ],
                true
            );
    }

    /**
     * Cancel follows the lifecycle guard, so the button never offers what the server refuses.
     *
     * @return bool
     */
    public function canCancel(): bool
    {
        return $this->authorization->isAllowed('Acme_SellerRefund::cancel')
            && $this->stateMachine->canCancel($this->getRefund());
    }

    /**
     * Whether the receipt download is permitted for the current admin user.
     *
     * @return bool
     */
    public function canDownloadReceipt(): bool
    {
        return $this->authorization->isAllowed('Acme_SellerRefund::receipt');
    }

    /**
     * Configuration for the refund action widget.
     *
     * @return array<string, string>
     */
    public function getActionsConfig(): array
    {
        return [
            'completeUrl' => $this->getActionUrl('complete'),
            'retryUrl' => $this->getActionUrl('retry'),
            'cancelUrl' => $this->getActionUrl('cancel'),
            'formKey' => $this->getFormKey(),
        ];
    }

    public function getActionUrl(string $action): string
    {
        return $this->getUrl('acme_refund/refund/' . $action, ['refund_id' => $this->getRefundId()]);
    }

    public function formatMoney(?string $amount, ?string $currency = null): string
    {
        $currency = $currency ?? $this->getRefund()->getCurrencyCode();

        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }
}
