<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Block\Adminhtml\Refund;

use Acme\SellerRefund\Api\Data\RefundEventInterface;
use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\RefundStateMachine;
use Acme\SellerRefund\Model\ResourceModel\RefundEvent\CollectionFactory as EventCollectionFactory;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Refund detail backing block: the stored refund, its figures rebuilt from the snapshot,
 * and the append-only audit trail.
 */
class View extends Template
{
    private ?RefundInterface $refund = null;

    public function __construct(
        Context $context,
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RefundTotalCalculator $calculator,
        private readonly EventCollectionFactory $eventCollectionFactory,
        private readonly AuthorizationInterface $authorization,
        private readonly RefundStateMachine $stateMachine,
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
        $order = $this->orderRepository->get($refund->getOrderId());

        return $this->calculator->fromSnapshot($refund, $items, $order);
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
