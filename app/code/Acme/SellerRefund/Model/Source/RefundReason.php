<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Controlled list of refund reason codes (FRD section 13).
 *
 * Single source for both the admin entry form and the server-side validator, so the codes an
 * operator can pick are always the codes the server accepts.
 */
class RefundReason implements OptionSourceInterface
{
    public const DEFECT = 'defect';
    public const SHORTAGE = 'shortage';
    public const CUSTOMER_RETURN = 'customer_return';
    public const PRICE_ADJUSTMENT = 'price_adjustment';
    public const OTHER = 'other';

    public const CODES = [
        self::DEFECT,
        self::SHORTAGE,
        self::CUSTOMER_RETURN,
        self::PRICE_ADJUSTMENT,
        self::OTHER,
    ];

    /**
     * Return the reason options for select elements.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::DEFECT, 'label' => __('Defect')],
            ['value' => self::SHORTAGE, 'label' => __('Shortage')],
            ['value' => self::CUSTOMER_RETURN, 'label' => __('Customer return')],
            ['value' => self::PRICE_ADJUSTMENT, 'label' => __('Price adjustment')],
            ['value' => self::OTHER, 'label' => __('Other')],
        ];
    }
}
