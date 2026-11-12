<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Observer;

use Acme\SellerRefund\Api\Data\RefundEventInterface;
use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Model\Email\RefundConfirmationSender;
use Acme\SellerRefund\Model\Event\EventRecorder;
use Acme\SellerRefund\Observer\SendConfirmationEmail;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The confirmation email outcome is written to the refund's audit log, and a mail failure never
 * breaks the confirmed refund.
 */
class SendConfirmationEmailTest extends TestCase
{
    /** @var RefundConfirmationSender&MockObject */
    private RefundConfirmationSender $sender;

    /** @var EventRecorder&MockObject */
    private EventRecorder $recorder;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->sender = $this->createMock(RefundConfirmationSender::class);
        $this->recorder = $this->createMock(EventRecorder::class);
    }

    /**
     * A sent email is recorded as succeeded.
     *
     * @return void
     */
    public function testSentEmailIsRecorded(): void
    {
        $this->sender->method('send')->willReturn(true);
        $this->expectRecord(RefundInterface::SUB_SUCCEEDED, []);

        $this->observer()->execute($this->event());
    }

    /**
     * A mail failure is recorded with a safe code and does not throw.
     *
     * @return void
     */
    public function testMailFailureIsRecordedAndSwallowed(): void
    {
        $this->sender->method('send')->willThrowException(new \RuntimeException('Unable to send mail'));
        $this->expectRecord(
            RefundEventInterface::EMAIL_NOT_SENT,
            ['error_code' => SendConfirmationEmail::ERROR_MAIL_FAILED]
        );

        $this->observer()->execute($this->event());
    }

    /**
     * An order without a customer email is recorded as skipped, not as sent.
     *
     * @return void
     */
    public function testMissingCustomerEmailIsRecordedAsSkipped(): void
    {
        $this->sender->method('send')->willReturn(false);
        $this->expectRecord(
            RefundEventInterface::EMAIL_SKIPPED,
            ['error_code' => SendConfirmationEmail::ERROR_NO_CUSTOMER_EMAIL]
        );

        $this->observer()->execute($this->event());
    }

    /**
     * Expect exactly one email audit event for refund 7.
     *
     * @param string $status
     * @param array<string, string> $context
     * @return void
     */
    private function expectRecord(string $status, array $context): void
    {
        $this->recorder->expects(self::once())
            ->method('record')
            ->with(7, RefundEventInterface::TYPE_EMAIL_CONFIRMATION, $status, $context);
    }

    /**
     * The observer under test.
     *
     * @return SendConfirmationEmail
     */
    private function observer(): SendConfirmationEmail
    {
        return new SendConfirmationEmail(
            $this->sender,
            $this->recorder,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * The acme_seller_refund_confirmed event for refund 7.
     *
     * @return Observer
     */
    private function event(): Observer
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(7);
        $refund->method('getRefundNo')->willReturn('SR-20261113-000007');

        return new Observer(['event' => new Event(['refund' => $refund])]);
    }
}
