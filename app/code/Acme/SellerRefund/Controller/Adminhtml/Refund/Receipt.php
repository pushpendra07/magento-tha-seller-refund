<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Controller\Adminhtml\Refund;

use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;

class Receipt extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Acme_SellerRefund::receipt';

    /**
     * @param Context $context
     * @param FileFactory $fileFactory
     * @param RefundRepositoryInterface $refundRepository
     * @param RefundReceipt $receipt
     */
    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly RefundReceipt $receipt
    ) {
        parent::__construct($context);
    }

    /**
     * Download a receipt through Magento's standard temporary-file response.
     *
     * @return ResponseInterface
     */
    public function execute(): ResponseInterface
    {
        $refund = $this->refundRepository->getById((int) $this->getRequest()->getParam('refund_id'));
        $content = $this->receipt->render($refund);

        return $this->fileFactory->create(
            $refund->getRefundNo() . '.pdf',
            ['type' => 'string', 'value' => $content, 'rm' => true],
            DirectoryList::VAR_DIR,
            'application/pdf'
        );
    }
}
