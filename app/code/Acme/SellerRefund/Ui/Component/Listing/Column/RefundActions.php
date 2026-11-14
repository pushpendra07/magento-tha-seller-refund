<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row action for the Seller Refunds grid: open the refund detail page.
 */
class RefundActions extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Add the View link to each row.
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        // Iterate the real array by reference; a '?? []' fallback would edit a copy.
        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['entity_id'])) {
                continue;
            }
            $item[$this->getData('name')] = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl(
                        'acme_refund/refund/view',
                        ['refund_id' => $item['entity_id']]
                    ),
                    'label' => __('View'),
                ],
            ];
        }
        unset($item);

        return $dataSource;
    }
}
