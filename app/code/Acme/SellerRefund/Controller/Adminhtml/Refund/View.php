<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Controller\Adminhtml\Refund;

use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

/**
 * Refund detail page.
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Acme_SellerRefund::view';

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param RefundRepositoryInterface $refundRepository
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly RefundRepositoryInterface $refundRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Show the refund, titled with its number; an unknown refund goes back to the worklist.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        try {
            $refund = $this->refundRepository->getById((int) $this->getRequest()->getParam('refund_id'));
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('This refund no longer exists.'));

            return $this->resultRedirectFactory->create()->setPath('acme_refund/refund/index');
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Acme_SellerRefund::refund');
        $page->getConfig()->getTitle()->prepend(__('Refund %1', $refund->getRefundNo()));

        return $page;
    }
}
