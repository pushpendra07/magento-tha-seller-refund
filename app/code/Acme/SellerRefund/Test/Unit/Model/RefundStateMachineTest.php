<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Exception\IllegalTransitionException;
use Acme\SellerRefund\Exception\StaleRefundException;
use Acme\SellerRefund\Model\Event\EventRecorder;
use Acme\SellerRefund\Model\Refund;
use Acme\SellerRefund\Model\RefundStateMachine;
use Acme\SellerRefund\Model\ResourceModel\Refund as RefundResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage of the lifecycle guard. The resource compare-and-set is mocked so the
 * legal/illegal/stale branches can be driven without a database.
 */
class RefundStateMachineTest extends TestCase
{
    private RefundResource&MockObject $refundResource;

    private EventRecorder&MockObject $eventRecorder;

    private RefundStateMachine $stateMachine;

    protected function setUp(): void
    {
        $this->refundResource = $this->createMock(RefundResource::class);
        $this->eventRecorder = $this->createMock(EventRecorder::class);
        $this->stateMachine = new RefundStateMachine($this->refundResource, $this->eventRecorder);
    }

    public function testLegalTransitionAppliesCasAndRecordsEvent(): void
    {
        $refund = $this->refund(RefundInterface::STATUS_CALCULATED, 1);

        $this->refundResource->expects(self::once())
            ->method('updateWithVersion')
            ->with(10, 1, self::callback(static function (array $data): bool {
                return ($data[RefundInterface::STATUS] ?? null) === RefundInterface::STATUS_CASH_REFUND_PENDING;
            }))
            ->willReturn(true);

        $this->eventRecorder->expects(self::once())->method('record');

        $this->stateMachine->transition($refund, RefundInterface::STATUS_CASH_REFUND_PENDING);
    }

    public function testStaleRefundRaisesWhenNoRowUpdated(): void
    {
        $refund = $this->refund(RefundInterface::STATUS_CALCULATED, 1);

        $this->refundResource->method('updateWithVersion')->willReturn(false);
        $this->eventRecorder->expects(self::never())->method('record');

        $this->expectException(StaleRefundException::class);
        $this->stateMachine->transition($refund, RefundInterface::STATUS_CASH_REFUND_PENDING);
    }

    public function testIllegalTransitionThrowsBeforeTouchingTheResource(): void
    {
        $refund = $this->refund(RefundInterface::STATUS_CALCULATED, 1);

        $this->refundResource->expects(self::never())->method('updateWithVersion');

        $this->expectException(IllegalTransitionException::class);
        $this->stateMachine->transition($refund, RefundInterface::STATUS_ERP_CONFIRMED);
    }

    /**
     * BR-09: cancellation is possible only before any Create Refund attempt, because once the
     * ERP may hold a credit note there is no way to void it.
     *
     * @dataProvider cancelProvider
     */
    public function testCanCancelOnlyBeforeAnyCreateAttempt(string $status, string $createStatus, bool $expected): void
    {
        $refund = $this->refund($status, 1);
        $refund->method('getCreateStatus')->willReturn($createStatus);

        self::assertSame($expected, $this->stateMachine->canCancel($refund));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function cancelProvider(): array
    {
        return [
            'calculated, create not started' => [
                RefundInterface::STATUS_CALCULATED, RefundInterface::SUB_NOT_STARTED, true,
            ],
            'calculated, create timed out (ERP may hold it)' => [
                RefundInterface::STATUS_CALCULATED, RefundInterface::SUB_RETRYABLE_ERROR, false,
            ],
            'credit note open in ERP' => [
                RefundInterface::STATUS_CASH_REFUND_PENDING, RefundInterface::SUB_SUCCEEDED, false,
            ],
            'cash refunded' => [
                RefundInterface::STATUS_CASH_REFUNDED, RefundInterface::SUB_SUCCEEDED, false,
            ],
        ];
    }

    private function refund(string $status, int $version): Refund&MockObject
    {
        $refund = $this->createMock(Refund::class);
        $refund->method('getStatus')->willReturn($status);
        $refund->method('getEntityId')->willReturn(10);
        $refund->method('getVersion')->willReturn($version);

        return $refund;
    }
}
