<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Source;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Operator-facing labels for the main refund lifecycle status (FRD section 7).
 *
 * One list for every admin surface that shows or filters by status, so the worklist filter
 * and the refund detail page always use the same words.
 */
class RefundStatus implements OptionSourceInterface
{
    /**
     * Status options in lifecycle order.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->labels() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * Label for a status code, or null when the code is not a main status.
     *
     * @param string $status
     * @return string|null
     */
    public function getLabel(string $status): ?string
    {
        $labels = $this->labels();

        return isset($labels[$status]) ? (string) $labels[$status] : null;
    }

    /**
     * Code => translated label. Literal strings, so the translation tools can collect them.
     *
     * @return array
     */
    private function labels(): array
    {
        return [
            RefundInterface::STATUS_CALCULATED => __('Saved, waiting for the ERP'),
            RefundInterface::STATUS_CASH_REFUND_PENDING => __('Waiting for the cash refund'),
            RefundInterface::STATUS_CASH_REFUNDED => __('Cash refunded, confirming with the ERP'),
            RefundInterface::STATUS_ERP_CONFIRM_PENDING => __('Confirming with the ERP'),
            RefundInterface::STATUS_ERP_CONFIRMED => __('Completed'),
            RefundInterface::STATUS_FAILED => __('Rejected by the ERP'),
            RefundInterface::STATUS_CANCELLED => __('Cancelled'),
            RefundInterface::STATUS_DRAFT => __('Draft'),
        ];
    }
}
