<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Tax;

use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * The consumption-tax rate the original order applied to a line (FRD section 11).
 *
 * A refund must use the rate the customer was actually charged, so the order item's stored
 * tax_percent wins. The product's business tax code is only a fallback for an order line that
 * carries no rate; it still decides the tax code sent to the ERP.
 */
class LineTaxRate
{
    private const SCALE = 4;

    /**
     * @param TaxCodeResolver $taxCodeResolver fallback rate when an order line carries none
     */
    public function __construct(
        private readonly TaxCodeResolver $taxCodeResolver
    ) {
    }

    /**
     * Rate as a fraction with four decimals, for example "0.1000" for 10%.
     *
     * @param OrderItemInterface $item
     * @return string
     */
    public function forItem(OrderItemInterface $item): string
    {
        $percent = $item->getTaxPercent();
        if ($percent !== null && $percent !== '') {
            return bcdiv(sprintf('%.4F', (float) $percent), '100', self::SCALE);
        }

        $code = $this->taxCodeResolver->toBusinessCode((int) $item->getData('mp_tax_class'));

        return $this->taxCodeResolver->rateFor($code);
    }
}
