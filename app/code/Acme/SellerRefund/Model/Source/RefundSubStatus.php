<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Source;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Operator-facing labels for the per-operation sub-statuses (create, status check, confirm,
 * cash refund), shared by the refund grid and the refund detail page.
 */
class RefundSubStatus implements OptionSourceInterface
{
    /**
     * Sub-status options.
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
     * Label for a sub-status code, or null when the code is unknown.
     *
     * @param string $code
     * @return string|null
     */
    public function getLabel(string $code): ?string
    {
        $labels = $this->labels();

        return isset($labels[$code]) ? (string) $labels[$code] : null;
    }

    /**
     * Code => translated label. Literal strings, so the translation tools can collect them.
     *
     * @return array
     */
    private function labels(): array
    {
        return [
            RefundInterface::SUB_NOT_STARTED => __('Not started'),
            RefundInterface::SUB_PENDING => __('In progress'),
            RefundInterface::SUB_SUCCEEDED => __('Succeeded'),
            RefundInterface::SUB_RETRYABLE_ERROR => __('Retrying'),
            RefundInterface::SUB_BUSINESS_REJECTED => __('Rejected'),
            RefundInterface::SUB_CONFLICT => __('Conflict, needs reconciliation'),
            RefundInterface::SUB_INVALID_PAYLOAD => __('Invalid data, not sent'),
            RefundInterface::SUB_RETRIES_EXHAUSTED => __('Retries exhausted, needs replay'),
        ];
    }
}
