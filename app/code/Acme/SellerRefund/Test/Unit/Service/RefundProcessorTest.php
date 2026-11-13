<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Service;

use Acme\SellerRefund\Api\Data\RefundEventInterface;
use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Exception\StaleRefundException;
use Acme\SellerRefund\Exception\ValidationException;
use Acme\SellerRefund\Model\Erp\CreateResult;
use Acme\SellerRefund\Model\Erp\ErpRefundClient;
use Acme\SellerRefund\Model\Erp\Exception\ErpBusinessException;
use Acme\SellerRefund\Model\Erp\Exception\ErpConflictException;
use Acme\SellerRefund\Model\Erp\Exception\ErpTransientException;
use Acme\SellerRefund\Model\Erp\PayloadBuilder;
use Acme\SellerRefund\Model\Erp\PayloadValidator;
use Acme\SellerRefund\Model\Event\EventRecorder;
use Acme\SellerRefund\Model\Outbox\Outbox;
use Acme\SellerRefund\Model\RefundEligibility;
use Acme\SellerRefund\Model\RefundFactory;
use Acme\SellerRefund\Model\RefundItemFactory;
use Acme\SellerRefund\Model\RefundNumberGenerator;
use Acme\SellerRefund\Model\RefundStateMachine;
use Acme\SellerRefund\Model\RefundValidator;
use Acme\SellerRefund\Model\ResourceModel\OrderLock;
use Acme\SellerRefund\Model\ResourceModel\PriorRefundQuantity;
use Acme\SellerRefund\Model\ResourceModel\Refund as RefundResource;
use Acme\SellerRefund\Model\ResourceModel\RefundItem as RefundItemResource;
use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Acme\SellerRefund\Service\RefundProcessor;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage of the Create dispatch in RefundProcessor::process(). All collaborators
 * are mocked; no database, HTTP or application bootstrap is touched.
 */
class RefundProcessorTest extends TestCase
{
    private RefundRepositoryInterface&MockObject $refundRepository;

    private OrderRepositoryInterface&MockObject $orderRepository;

    private PayloadBuilder&MockObject $payloadBuilder;

    private ErpRefundClient&MockObject $client;

    private RefundStateMachine&MockObject $stateMachine;

    private EventRecorder&MockObject $eventRecorder;

    /**
     * @var PayloadValidator&MockObject
     */
    private PayloadValidator&MockObject $payloadValidator;

    /**
     * @var TimezoneInterface&MockObject
     */
    private TimezoneInterface&MockObject $timezone;

    /**
     * @var ResourceConnection|MockObject
     */
    private $resource;

    /**
     * @var Outbox|MockObject
     */
    private $outbox;

    private RefundProcessor $processor;

    protected function setUp(): void
    {
        $this->refundRepository = $this->createMock(RefundRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->payloadBuilder = $this->createMock(PayloadBuilder::class);
        $this->client = $this->createMock(ErpRefundClient::class);
        $this->stateMachine = $this->createMock(RefundStateMachine::class);
        $this->eventRecorder = $this->createMock(EventRecorder::class);
        $this->payloadValidator = $this->createMock(PayloadValidator::class);
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('date')->willReturn(new \DateTime('2026-11-12'));
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->outbox = $this->createMock(Outbox::class);

        $this->processor = new RefundProcessor(
            $this->refundRepository,
            $this->resource,
            $this->createMock(OrderLock::class),
            $this->orderRepository,
            $this->createMock(PriorRefundQuantity::class),
            $this->createMock(RefundValidator::class),
            $this->createMock(RefundTotalCalculator::class),
            $this->createMock(RefundNumberGenerator::class),
            $this->createMock(RefundFactory::class),
            $this->createMock(RefundItemFactory::class),
            $this->createMock(RefundResource::class),
            $this->createMock(RefundItemResource::class),
            $this->createMock(SellerLineResolver::class),
            $this->eventRecorder,
            $this->outbox,
            $this->stateMachine,
            $this->client,
            $this->payloadBuilder,
            $this->createMock(EventManagerInterface::class),
            $this->timezone,
            $this->createMock(RefundEligibility::class),
            $this->payloadValidator
        );
    }

    /**
     * The create-idempotency guard: when the ledger already shows a succeeded create for this
     * refund_no, process() returns without calling the ERP client.
     */
    public function testProcessSkipsCreateWhenAlreadySucceededUpstream(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CALCULATED);
        $refund->method('getRefundNo')->willReturn('SR-20260907-000123');

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->client->method('hasSucceededCreate')->with('SR-20260907-000123')->willReturn(true);

        $this->client->expects(self::never())->method('create');
        $this->stateMachine->expects(self::never())->method('transition');
        $this->payloadBuilder->expects(self::never())->method('build');

        $this->processor->process(7);
    }

    public function testProcessHappyPathCreatesAndTransitionsToCashRefundPending(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CALCULATED);
        $refund->method('getRefundNo')->willReturn('SR-20260907-000123');
        $refund->method('getOrderId')->willReturn(100);

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->refundRepository->method('getItems')->with(7)->willReturn([]);
        $this->orderRepository->method('get')->with(100)->willReturn(
            $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class)
        );
        $this->client->method('hasSucceededCreate')->willReturn(false);

        $payload = ['refund_no' => 'SR-20260907-000123'];
        $this->payloadBuilder->method('build')->willReturn($payload);
        $this->client->expects(self::once())
            ->method('create')
            ->with($payload, 1)
            ->willReturn(new CreateResult('ERP-9001', 'refund-pending'));

        $this->stateMachine->expects(self::once())
            ->method('transition')
            ->with(
                self::identicalTo($refund),
                RefundInterface::STATUS_CASH_REFUND_PENDING,
                \Acme\SellerRefund\Api\Data\RefundEventInterface::TYPE_ERP_CREATE,
                [
                    RefundInterface::CREATE_STATUS => RefundInterface::SUB_SUCCEEDED,
                    RefundInterface::ERP_REFUND_ID => 'ERP-9001',
                ]
            );

        $this->processor->process(7);
    }

    public function testProcessTransientErrorHoldsCalculatedAndRethrows(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CALCULATED);
        $refund->method('getRefundNo')->willReturn('SR-20260907-000123');
        $refund->method('getOrderId')->willReturn(100);

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->refundRepository->method('getItems')->with(7)->willReturn([]);
        $this->orderRepository->method('get')->with(100)->willReturn(
            $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class)
        );
        $this->client->method('hasSucceededCreate')->willReturn(false);
        $this->payloadBuilder->method('build')->willReturn(['refund_no' => 'SR-20260907-000123']);
        $this->client->method('create')->willThrowException(
            new ErpTransientException('The ERP returned a transient error (HTTP 503).', 503)
        );

        // A transient failure sets create_status to retryable_error and never advances status.
        $subStatuses = $this->recordCreateSubStatuses($refund);
        $this->stateMachine->expects(self::never())->method('transition');

        try {
            $this->processor->process(7);
            self::fail('The transient error must be rethrown for the outbox to retry.');
        } catch (ErpTransientException $e) {
            self::assertSame([RefundInterface::SUB_PENDING, RefundInterface::SUB_RETRYABLE_ERROR], $subStatuses->list);
        }
    }

    /**
     * HTTP 409 means the ERP already holds a credit note for this refund_no. The refund must
     * stay at calculated (still counting its quantity) instead of moving to failed, which would
     * release the quantity for a second refund.
     */
    public function testProcessConflictHoldsTheRefundForReconciliation(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CALCULATED);
        $refund->method('getRefundNo')->willReturn('SR-20260907-000123');
        $refund->method('getOrderId')->willReturn(100);

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->refundRepository->method('getItems')->with(7)->willReturn([]);
        $this->orderRepository->method('get')->with(100)->willReturn(
            $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class)
        );
        $this->client->method('hasSucceededCreate')->willReturn(false);
        $this->payloadBuilder->method('build')->willReturn(['refund_no' => 'SR-20260907-000123']);
        $this->client->method('create')->willThrowException(
            new ErpConflictException('The ERP reported a conflict (HTTP 409).')
        );

        $subStatuses = $this->recordCreateSubStatuses($refund);
        $this->stateMachine->expects(self::never())->method('transition');

        $this->processor->process(7);
        self::assertSame([RefundInterface::SUB_PENDING, RefundInterface::SUB_CONFLICT], $subStatuses->list);
    }

    /**
     * BR-09: a cancel that committed after this worker read the refund makes the Create claim
     * fail, and the ERP is then never called, so no credit note exists for a cancelled refund.
     */
    public function testCreateIsNotSentWhenTheRefundChangedBeforeTheClaim(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CALCULATED);
        $refund->method('getRefundNo')->willReturn('SR-20260907-000123');
        $refund->method('getOrderId')->willReturn(100);

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->refundRepository->method('getItems')->with(7)->willReturn([]);
        $this->orderRepository->method('get')->with(100)->willReturn(
            $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class)
        );
        $this->client->method('hasSucceededCreate')->willReturn(false);
        $this->payloadBuilder->method('build')->willReturn(['refund_no' => 'SR-20260907-000123']);
        $this->stateMachine->expects(self::once())
            ->method('setSubStatus')
            ->with($refund, RefundInterface::CREATE_STATUS, RefundInterface::SUB_PENDING)
            ->willThrowException(new StaleRefundException(__('The refund was modified by another process.')));

        $this->client->expects(self::never())->method('create');
        $this->stateMachine->expects(self::never())->method('transition');

        $this->processor->process(7);
    }

    /**
     * The move to erp_confirm_pending and the Confirm job are committed together: when queueing
     * the job fails, the state change is rolled back, so a refund is never left waiting for a
     * Confirm that nothing will send.
     */
    public function testConfirmPendingIsRolledBackWhenTheConfirmJobCannotBeQueued(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CASH_REFUNDED);
        $refund->method('getErpRefundId')->willReturn('ERP-8000123');
        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->client->method('readStatus')->willReturn([
            'erp_refund_id' => 'ERP-8000123',
            'status' => 'refund-pending',
        ]);

        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $calls = [];
        $connection->method('beginTransaction')->willReturnCallback(
            function () use (&$calls, $connection) {
                $calls[] = 'begin';

                return $connection;
            }
        );
        $this->stateMachine->expects(self::once())->method('transition')->willReturnCallback(
            function () use (&$calls): void {
                $calls[] = 'transition';
            }
        );
        $this->outbox->expects(self::once())->method('enqueue')
            ->with(7, Outbox::OP_CONFIRM)
            ->willThrowException(new \RuntimeException('Lost connection to the database.'));
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollBack')->willReturnCallback(
            function () use (&$calls, $connection) {
                $calls[] = 'rollBack';

                return $connection;
            }
        );

        try {
            $this->processor->checkStatus(7);
            self::fail('The enqueue failure must reach the outbox worker.');
        } catch (\RuntimeException $e) {
            self::assertSame(['begin', 'transition', 'rollBack'], $calls);
        }
    }

    /**
     * FRD 9.6: a rejected or conflicting Confirm is shown on the refund (not left "In progress"),
     * keeps the main status because the cash is already paid, and reaches the outbox so the job
     * is dead-lettered for an operator.
     *
     * @param \Throwable $erpError
     * @param string $subStatus
     * @param string $errorCode
     * @return void
     * @dataProvider confirmRejectionProvider
     */
    public function testRejectedConfirmIsRecordedAndHeldForAnOperator(
        \Throwable $erpError,
        string $subStatus,
        string $errorCode
    ): void {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(7);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_ERP_CONFIRM_PENDING);
        $refund->method('getErpRefundId')->willReturn('ERP-8000123');
        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->client->method('confirm')->willThrowException($erpError);

        $written = [];
        $this->stateMachine->method('setSubStatus')->willReturnCallback(
            function ($target, string $field, string $value) use (&$written): void {
                $written[] = $field . '=' . $value;
            }
        );
        $this->stateMachine->expects(self::never())->method('transition');
        $this->eventRecorder->expects(self::once())->method('record')->with(
            7,
            RefundEventInterface::TYPE_ERP_CONFIRM,
            $subStatus,
            self::callback(fn (array $data): bool => $data['error_code'] === $errorCode)
        );

        try {
            $this->processor->confirm(7);
            self::fail('The rejection must reach the outbox to dead-letter the job.');
        } catch (\Throwable $e) {
            self::assertSame($erpError, $e);
        }
        self::assertSame(
            [
                RefundInterface::CONFIRM_STATUS . '=' . RefundInterface::SUB_PENDING,
                RefundInterface::CONFIRM_STATUS . '=' . $subStatus,
            ],
            $written
        );
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string, 2: string}>
     */
    public static function confirmRejectionProvider(): array
    {
        return [
            'business rejection' => [
                new ErpBusinessException('The ERP rejected the refund (HTTP 422).', 'invalid_transaction'),
                RefundInterface::SUB_BUSINESS_REJECTED,
                'invalid_transaction',
            ],
            'conflict' => [
                new ErpConflictException('The ERP reported a conflicting refund state.'),
                RefundInterface::SUB_CONFLICT,
                'conflict',
            ],
        ];
    }

    /**
     * The same for Read Refund Status: a conflict is recorded on the status-check sub-status.
     *
     * @return void
     */
    public function testConflictingStatusReadIsRecordedAndHeldForAnOperator(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(7);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CASH_REFUNDED);
        $refund->method('getErpRefundId')->willReturn('ERP-8000123');
        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->client->method('readStatus')->willThrowException(
            new ErpConflictException('The ERP reported a conflicting refund state.')
        );

        $written = [];
        $this->stateMachine->method('setSubStatus')->willReturnCallback(
            function ($target, string $field, string $value) use (&$written): void {
                $written[] = $field . '=' . $value;
            }
        );
        $this->stateMachine->expects(self::never())->method('transition');

        $this->expectException(ErpConflictException::class);
        try {
            $this->processor->checkStatus(7);
        } finally {
            self::assertSame(
                [
                    RefundInterface::STATUS_CHECK_STATUS . '=' . RefundInterface::SUB_PENDING,
                    RefundInterface::STATUS_CHECK_STATUS . '=' . RefundInterface::SUB_CONFLICT,
                ],
                $written
            );
        }
    }

    /**
     * Collects the create_status values written, in order.
     *
     * @param RefundInterface $refund
     * @return \stdClass
     */
    private function recordCreateSubStatuses(RefundInterface $refund): \stdClass
    {
        $written = new \stdClass();
        $written->list = [];
        $this->stateMachine->method('setSubStatus')->willReturnCallback(
            function ($target, string $field, string $value) use ($refund, $written): void {
                self::assertSame($refund, $target);
                self::assertSame(RefundInterface::CREATE_STATUS, $field);
                $written->list[] = $value;
            }
        );

        return $written;
    }

    /**
     * Read-before-confirm: when the ERP no longer reports the credit note as refund-pending
     * (here it is already confirmed), the refund must not advance to erp_confirm_pending.
     */
    public function testStatusCheckRefusesACreditNoteThatIsNotPending(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CASH_REFUNDED);
        $refund->method('getErpRefundId')->willReturn('ERP-8000123');

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->client->method('readStatus')->willReturn([
            'erp_refund_id' => 'ERP-8000123',
            'status' => 'refund-confirmed',
        ]);

        $this->stateMachine->expects(self::never())->method('transition');

        $this->expectException(ErpBusinessException::class);
        $this->processor->checkStatus(7);
    }

    /**
     * The audit trail records which retry of Confirm this was (FRD 12.2 attempt history).
     */
    public function testConfirmRecordsTheOutboxAttemptNumber(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_ERP_CONFIRM_PENDING);
        $refund->method('getErpRefundId')->willReturn('ERP-8000123');

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->payloadBuilder->method('buildConfirm')->willReturn([]);
        $this->client->method('confirm')->willThrowException(
            new ErpTransientException('The ERP returned a transient error (HTTP 503).', 503)
        );

        $this->eventRecorder->expects(self::once())
            ->method('record')
            ->with(
                7,
                self::anything(),
                RefundInterface::SUB_RETRYABLE_ERROR,
                self::callback(static fn (array $ctx): bool => ($ctx['attempt_no'] ?? null) === 3)
            );

        $this->expectException(ErpTransientException::class);
        $this->processor->confirm(7, 3);
    }

    /**
     * An invalid Create request is stopped locally, with no HTTP call, and dead-lettered.
     */
    public function testInvalidCreatePayloadIsNeverSentToTheErp(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CALCULATED);
        $refund->method('getRefundNo')->willReturn('SR-20260907-000123');
        $refund->method('getOrderId')->willReturn(100);

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->refundRepository->method('getItems')->with(7)->willReturn([]);
        $this->orderRepository->method('get')->with(100)->willReturn(
            $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class)
        );
        $this->client->method('hasSucceededCreate')->willReturn(false);
        $this->payloadBuilder->method('build')->willReturn(['lines' => [['quantity' => 0]]]);
        $this->payloadValidator->method('validateCreate')->willThrowException(
            new ValidationException(__('invalid'))
        );

        $this->client->expects(self::never())->method('create');
        $this->stateMachine->expects(self::once())
            ->method('setSubStatus')
            ->with($refund, RefundInterface::CREATE_STATUS, RefundInterface::SUB_INVALID_PAYLOAD);

        $this->expectException(ValidationException::class);
        $this->processor->process(7);
    }

    /**
     * BR-10: bad bank transaction input is refused before the refund is loaded.
     *
     * @dataProvider invalidCashRegistrationProvider
     * @param string $number
     * @param string $date
     * @param string $message
     */
    public function testInvalidCashRegistrationIsRefused(string $number, string $date, string $message): void
    {
        $this->refundRepository->expects(self::never())->method('getById');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);
        $this->processor->registerCashRefund(7, $number, $date, 1);
    }

    /**
     * Invalid bank transaction inputs and the message each must produce.
     *
     * @return array
     */
    public static function invalidCashRegistrationProvider(): array
    {
        return [
            'empty number' => ['  ', '2026-11-12', 'Enter the bank transaction number.'],
            'number too long' => [str_repeat('9', 65), '2026-11-12', 'at most 64 characters'],
            'not a date' => ['TXN-1', 'not-a-date', 'YYYY-MM-DD'],
            'impossible date' => ['TXN-1', '2026-02-30', 'YYYY-MM-DD'],
            'future date' => ['TXN-1', '2026-11-13', 'cannot be in the future'],
        ];
    }
}
