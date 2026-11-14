<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Money column for the Seller Refunds grid, shown the same way as on the refund pages
 * ("JPY 6,519"), in the refund's own currency.
 */
class Money extends Column
{
    /**
     * Format the column value in each row.
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        $field = $this->getData('name');
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        // Iterate the real array by reference; a '?? []' fallback would edit a copy.
        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item[$field]) || $item[$field] === '') {
                continue;
            }
            $currency = (string) ($item['currency_code'] ?? 'JPY');
            $item[$field] = $currency . ' ' . number_format((float) $item[$field], 0, '.', ',');
        }
        unset($item);

        return $dataSource;
    }
}
