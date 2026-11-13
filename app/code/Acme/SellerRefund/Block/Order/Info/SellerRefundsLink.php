<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Block\Order\Info;

use Acme\SellerRefund\Model\Presentation\CustomerVisibleRefunds;
use Magento\Framework\App\DefaultPathInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Block\Order\Link;

/**
 * "Seller Refunds" tab in the order's tab bar, shown only when the order has a refund the
 * customer can see, as Magento shows "Refunds" only when credit memos exist.
 */
class SellerRefundsLink extends Link
{
    /**
     * @param Context $context
     * @param DefaultPathInterface $defaultPath
     * @param Registry $registry
     * @param CustomerVisibleRefunds $visibleRefunds
     * @param array $data
     */
    public function __construct(
        Context $context,
        DefaultPathInterface $defaultPath,
        Registry $registry,
        private readonly CustomerVisibleRefunds $visibleRefunds,
        array $data = []
    ) {
        parent::__construct($context, $defaultPath, $registry, $data);
    }

    /**
     * Render the tab only for an order with visible seller refunds.
     *
     * @return string
     */
    protected function _toHtml()
    {
        $order = $this->_registry->registry('current_order');
        if (!$order instanceof OrderInterface
            || !$this->visibleRefunds->hasAny((int) $order->getEntityId())
        ) {
            return '';
        }

        return parent::_toHtml();
    }
}
