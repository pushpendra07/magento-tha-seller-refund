<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Controller\Adminhtml\Refund;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Controller\Adminhtml\Refund\Save;
use Acme\SellerRefund\Model\Outbox\Dispatcher;
use Acme\SellerRefund\Model\Outbox\Outbox;
use Acme\SellerRefund\Service\RefundProcessor;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;

/**
 * The save action commits the refund first and only then tries the ERP once, so an ERP
 * outage never loses the refund and never ends it in the terminal failed state.
 */
class SaveTest extends TestCase
{
    /**
     * ERP unavailable during save: the refund stays saved and retryable, and the operator is told so.
     *
     * @return void
     */
    public function testErpOutageDuringSaveKeepsTheRefundSavedAndRetryable(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('order_id')->willReturn('100');
        $request->method('getParams')->willReturn([
            'order_id' => '100',
            'reason_code' => 'damaged',
            'items' => [501 => '1'],
        ]);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getAuth')->willReturn($this->createMock(Auth::class));
        $helper = $this->createMock(BackendHelper::class);
        $helper->method('getUrl')->willReturn('http://admin.test/acme_refund/refund/view/refund_id/7/');
        $context->method('getHelper')->willReturn($helper);

        $submitted = $this->createMock(RefundInterface::class);
        $submitted->method('getEntityId')->willReturn(7);
        $processor = $this->createMock(RefundProcessor::class);
        $processor->expects(self::once())->method('submit')->willReturn($submitted);

        // The dispatcher absorbs the transient ERP error and reschedules the outbox job.
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects(self::once())->method('run')->with(Outbox::OP_CREATE, 1, 7)->willReturn(1);

        $fresh = $this->createMock(RefundInterface::class);
        $fresh->method('getEntityId')->willReturn(7);
        $fresh->method('getRefundNo')->willReturn('SR-20260907-000123');
        $fresh->method('getStatus')->willReturn(RefundInterface::STATUS_CALCULATED);
        $fresh->method('getCreateStatus')->willReturn(RefundInterface::SUB_RETRYABLE_ERROR);
        $repository = $this->createMock(RefundRepositoryInterface::class);
        $repository->method('getById')->with(7)->willReturn($fresh);

        $json = $this->createMock(Json::class);
        $json->expects(self::never())->method('setHttpResponseCode');
        $json->expects(self::once())
            ->method('setData')
            ->with(self::callback(static function (array $data): bool {
                return $data['ok'] === true
                    && $data['refund_no'] === 'SR-20260907-000123'
                    && $data['status'] === RefundInterface::STATUS_CALCULATED
                    && $data['create_status'] === RefundInterface::SUB_RETRYABLE_ERROR;
            }))
            ->willReturnSelf();
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $action = new Save($context, $jsonFactory, $processor, $dispatcher, $repository);

        self::assertSame($json, $action->execute());
    }
}
