<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Controller\Adminhtml\Refund;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Controller\Adminhtml\Refund\Receipt;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use PHPUnit\Framework\TestCase;

/**
 * Downloads use the standard PDF file response and clean up the temporary file.
 */
class ReceiptTest extends TestCase
{
    /**
     * @return void
     */
    public function testDownloadUsesMagentoFileFactory(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('refund_id')->willReturn(7);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getRefundNo')->willReturn('SR-20261113-000007');
        $repository = $this->createMock(RefundRepositoryInterface::class);
        $repository->expects(self::once())->method('getById')->with(7)->willReturn($refund);
        $receipt = $this->createMock(RefundReceipt::class);
        $receipt->expects(self::once())->method('render')->with($refund)->willReturn('%PDF-receipt');
        $response = $this->createMock(ResponseInterface::class);
        $factory = $this->createMock(FileFactory::class);
        $factory->expects(self::once())->method('create')->with(
            'SR-20261113-000007.pdf',
            ['type' => 'string', 'value' => '%PDF-receipt', 'rm' => true],
            DirectoryList::VAR_DIR,
            'application/pdf'
        )->willReturn($response);

        self::assertSame($response, (new Receipt($context, $factory, $repository, $receipt))->execute());
        self::assertSame('Acme_SellerRefund::receipt', Receipt::ADMIN_RESOURCE);
    }
}
