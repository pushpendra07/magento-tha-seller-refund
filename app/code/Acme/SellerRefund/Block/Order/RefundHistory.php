<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Block\Order;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Model\Presentation\CustomerVisibleRefunds;
use Acme\SellerRefund\Model\ResourceModel\Refund\Collection as RefundCollection;
use Acme\SellerRefund\Model\ResourceModel\Refund\CollectionFactory as RefundCollectionFactory;
use Acme\SellerRefund\Model\ResourceModel\RefundItem\CollectionFactory as RefundItemCollectionFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Theme\Block\Html\Pager;

/**
 * "Refunded Orders" table under My Orders (FRD 10.4: the refunded order stays visible with the
 * refunded items marked).
 *
 * Built like Magento's own order history block: one paged query over the customer's refunds
 * (joined to the order only to filter by customer), newest first, with Magento's pager. The pager
 * uses its own URL parameters, so it pages independently of the orders table above it.
 */
class RefundHistory extends Template
{
    public const PAGE_VAR = 'rp';
    public const LIMIT_VAR = 'rlimit';

    /**
     * @var RefundCollection|null
     */
    private ?RefundCollection $refunds = null;

    /**
     * @var array<int, string[]>|null refund id => refunded SKUs, for the current page
     */
    private ?array $skus = null;

    /**
     * @param Context $context
     * @param RefundCollectionFactory $refundCollectionFactory
     * @param RefundItemCollectionFactory $itemCollectionFactory
     * @param CustomerSession $customerSession
     * @param PriceCurrencyInterface $priceCurrency
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly RefundCollectionFactory $refundCollectionFactory,
        private readonly RefundItemCollectionFactory $itemCollectionFactory,
        private readonly CustomerSession $customerSession,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The signed-in customer's visible refunds, newest first; paged by the pager child.
     *
     * @return RefundCollection|null null when no customer is signed in
     */
    public function getRefunds(): ?RefundCollection
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId === 0) {
            return null;
        }
        if ($this->refunds === null) {
            $collection = $this->refundCollectionFactory->create();
            $collection->getSelect()->join(
                ['so' => $collection->getTable('sales_order')],
                'so.entity_id = main_table.order_id',
                ['order_increment_id' => 'so.increment_id']
            );
            $collection->addFieldToFilter('so.customer_id', $customerId)
                ->addFieldToFilter('main_table.status', ['nin' => CustomerVisibleRefunds::HIDDEN_STATUSES])
                ->setOrder('main_table.entity_id', RefundCollection::SORT_ORDER_DESC);
            $this->refunds = $collection;
        }

        return $this->refunds;
    }

    /**
     * Refunded SKUs of one refund on the current page (read for the whole page in one query).
     *
     * @param RefundInterface $refund
     * @return string[]
     */
    public function getRefundedSkus(RefundInterface $refund): array
    {
        if ($this->skus === null) {
            $this->skus = [];
            $refunds = $this->getRefunds();
            $ids = $refunds ? array_map('intval', $refunds->getColumnValues('entity_id')) : [];
            if ($ids !== []) {
                $items = $this->itemCollectionFactory->create()
                    ->addFieldToFilter('refund_id', ['in' => $ids])
                    ->setOrder('entity_id', 'ASC');
                foreach ($items as $item) {
                    $this->skus[(int) $item->getRefundId()][] = (string) $item->getSku();
                }
            }
        }

        return array_values(array_unique($this->skus[(int) $refund->getEntityId()] ?? []));
    }

    /**
     * Link to the order's Seller Refunds tab.
     *
     * @param RefundInterface $refund
     * @return string
     */
    public function getViewUrl(RefundInterface $refund): string
    {
        return $this->getUrl('sellerrefund/order/view', ['order_id' => (int) $refund->getOrderId()]);
    }

    /**
     * Refund total in its currency's own format, as the order pages show amounts (e.g. "￥864").
     *
     * @param RefundInterface $refund
     * @return string
     */
    public function formatTotal(RefundInterface $refund): string
    {
        return (string) $this->priceCurrency
            ->getCurrency(null, $refund->getCurrencyCode())
            ->formatTxt((float) $refund->getGrandTotal());
    }

    /**
     * Refund date in the store's short date format.
     *
     * @param RefundInterface $refund
     * @return string
     */
    public function formatRefundDate(RefundInterface $refund): string
    {
        return (string) $this->formatDate($refund->getCreatedAt());
    }

    /**
     * Pager HTML.
     *
     * @return string
     */
    public function getPagerHtml(): string
    {
        return $this->getChildHtml('pager');
    }

    /**
     * Attach Magento's pager with its own page and limit parameters, as the order history does.
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        $refunds = $this->getRefunds();
        if ($refunds !== null) {
            /** @var Pager $pager */
            $pager = $this->getLayout()->createBlock(Pager::class, 'acme.seller.refund.history.pager');
            $pager->setPageVarName(self::PAGE_VAR)
                ->setLimitVarName(self::LIMIT_VAR)
                ->setAvailableLimit([10 => 10, 20 => 20, 50 => 50])
                ->setShowPerPage(true)
                ->setCollection($refunds);
            $this->setChild('pager', $pager);
        }

        return $this;
    }
}
