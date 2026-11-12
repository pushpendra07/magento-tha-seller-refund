<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Observer;

use Acme\SellerRefund\Api\Data\RefundEventInterface;
use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Model\Email\RefundConfirmationSender;
use Acme\SellerRefund\Model\Event\EventRecorder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the refund confirmation email when a refund reaches ERP-confirmed.
 *
 * A mail failure is logged and swallowed so it never rolls back the confirmed lifecycle state.
 * The outcome is also written to the refund's audit log, so an operator can see on the refund
 * whether the customer was sent the confirmation (FRD 10.4) without reading server logs.
 */
class SendConfirmationEmail implements ObserverInterface
{
    public const ERROR_MAIL_FAILED = 'mail_failed';
    public const ERROR_NO_CUSTOMER_EMAIL = 'no_customer_email';

    /**
     * @param RefundConfirmationSender $sender
     * @param EventRecorder $eventRecorder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RefundConfirmationSender $sender,
        private readonly EventRecorder $eventRecorder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send the email and record the outcome.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $refund = $observer->getEvent()->getData('refund');
        if (!$refund instanceof RefundInterface) {
            return;
        }

        try {
            $sent = $this->sender->send($refund);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Refund confirmation email failed: ' . $e->getMessage(),
                ['refund_no' => $refund->getRefundNo()]
            );
            $this->record($refund, RefundEventInterface::EMAIL_NOT_SENT, self::ERROR_MAIL_FAILED);

            return;
        }

        if ($sent) {
            $this->record($refund, RefundInterface::SUB_SUCCEEDED, null);
        } else {
            $this->record($refund, RefundEventInterface::EMAIL_SKIPPED, self::ERROR_NO_CUSTOMER_EMAIL);
        }
    }

    /**
     * Write the audit event; a failure here is logged and never breaks the confirmation.
     *
     * @param RefundInterface $refund
     * @param string $status
     * @param string|null $errorCode
     * @return void
     */
    private function record(RefundInterface $refund, string $status, ?string $errorCode): void
    {
        try {
            $this->eventRecorder->record(
                (int) $refund->getEntityId(),
                RefundEventInterface::TYPE_EMAIL_CONFIRMATION,
                $status,
                $errorCode !== null ? ['error_code' => $errorCode] : []
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Recording the refund confirmation email outcome failed: ' . $e->getMessage(),
                ['refund_no' => $refund->getRefundNo()]
            );
        }
    }
}
