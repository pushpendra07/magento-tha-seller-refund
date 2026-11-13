<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Controller\Order;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Controller\AbstractController\OrderLoaderInterface;
use Magento\Sales\Controller\AbstractController\View as AbstractOrderView;
use Magento\Sales\Controller\OrderInterface as OrderControllerInterface;

/**
 * "Seller Refunds" tab of a customer's order (FRD 10.4).
 *
 * Built like Magento's own Refunds tab (Magento\Sales\Controller\Order\Creditmemo): the core order
 * loader admits only the signed-in customer's own order, and the core Authentication plugin
 * (etc/frontend/di.xml) sends guests to the login page.
 */
class View extends AbstractOrderView implements OrderControllerInterface, HttpGetActionInterface
{
    /**
     * @param Context $context
     * @param OrderLoaderInterface $orderLoader
     * @param PageFactory $resultPageFactory
     * @param Registry $registry
     */
    public function __construct(
        Context $context,
        OrderLoaderInterface $orderLoader,
        PageFactory $resultPageFactory,
        private readonly Registry $registry
    ) {
        parent::__construct($context, $orderLoader, $resultPageFactory);
    }

    /**
     * Render the order's seller refunds under the order title, as the core order tabs do.
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $result = parent::execute();
        if ($result instanceof Redirect) {
            // The core loader sends a refused order to "*/*/history", which does not exist on this
            // route; send the customer to Magento's My Orders instead.
            return $this->resultRedirectFactory->create()->setPath('sales/order/history');
        }
        $order = $this->registry->registry('current_order');
        if ($result instanceof Page && $order instanceof OrderInterface) {
            $result->getConfig()->getTitle()->set(__('Order # %1', $order->getIncrementId()));
        }

        return $result;
    }
}
