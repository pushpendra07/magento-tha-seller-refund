<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Erp;

use Acme\SellerRefund\Exception\ValidationException;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;

/**
 * Checks an ERP request before it is sent (FRD section 9, contract in erp-refund-stub).
 *
 * Everything here is a pure check on data already loaded: no database query and no HTTP call.
 * A request that fails can never succeed at the ERP, so it is stopped locally instead of being
 * rejected (422) or retried for nothing; the outbox job is dead-lettered for an operator.
 */
class PayloadValidator
{
    private const SCALE = 4;
    private const MONEY = '/^\d+\.\d{4}$/';
    private const ERP_REFUND_ID = '/^ERP-\d+$/';
    private const TAX_MODE = 'TAX_EXCLUDED';

    /**
     * Errors for an ERP credit-note identifier that is unsafe or malformed.
     *
     * @param TaxCodeResolver $taxCodeResolver knows the business tax codes the ERP accepts
     */
    public function __construct(
        private readonly TaxCodeResolver $taxCodeResolver
    ) {
    }

    /**
     * Validate a Create Refund request body.
     *
     * @param array $payload
     * @return void
     * @throws ValidationException
     */
    public function validateCreate(array $payload): void
    {
        $errors = [];

        // A missing refund_no makes the ERP open a new credit note on every attempt (BR-07).
        foreach (['refund_no', 'seller_order_id'] as $field) {
            if (!is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                $errors[] = sprintf('%s is missing', $field);
            }
        }
        if (!preg_match('/^[A-Z]{3}$/', (string) ($payload['currency'] ?? ''))) {
            $errors[] = 'currency is not a 3-letter code';
        }
        if (($payload['tax_mode'] ?? null) !== self::TAX_MODE) {
            $errors[] = 'tax_mode must be ' . self::TAX_MODE;
        }

        $lines = $payload['lines'] ?? null;
        if (!is_array($lines) || $lines === []) {
            $errors[] = 'there are no lines';
            $lines = [];
        }

        $sum = '0.0000';
        foreach ($lines as $index => $line) {
            $label = sprintf('line %d', $index + 1);
            if (!is_string($line['sku'] ?? null) || $line['sku'] === '') {
                $errors[] = $label . ': sku is missing';
            }
            if (!is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                $errors[] = $label . ': quantity must be a whole number of at least 1';
            }
            if (!$this->isMoney($line['amount'] ?? null)) {
                $errors[] = $label . ': amount is not a 4-decimal amount';
            } else {
                $sum = bcadd($sum, $line['amount'], self::SCALE);
            }

            $taxes = $line['taxes'] ?? null;
            if (!is_array($taxes) || $taxes === []) {
                $errors[] = $label . ': taxes are missing';
                continue;
            }
            foreach ($taxes as $tax) {
                $code = (string) ($tax['code'] ?? '');
                if (!$this->isKnownTaxCode($code)) {
                    $errors[] = sprintf('%s: tax code "%s" is not a business tax code', $label, $code);
                }
                if (!$this->isMoney($tax['amount'] ?? null)) {
                    $errors[] = $label . ': tax amount is not a 4-decimal amount';
                } else {
                    $sum = bcadd($sum, $tax['amount'], self::SCALE);
                }
            }
        }

        foreach (['shipping_amount', 'grand_total'] as $field) {
            if (!$this->isMoney($payload[$field] ?? null)) {
                $errors[] = sprintf('%s is not a 4-decimal amount', $field);
            }
        }

        if ($errors === [] && bccomp(
            bcadd($sum, $payload['shipping_amount'], self::SCALE),
            $payload['grand_total'],
            self::SCALE
        ) !== 0) {
            $errors[] = 'lines, taxes and shipping do not add up to grand_total';
        }

        $this->throwIfAny('Create Refund', $errors);
    }

    /**
     * Validate a Confirm Refund request (BR-08, BR-10).
     *
     * @param string $erpRefundId
     * @param array $payload
     * @return void
     * @throws ValidationException
     */
    public function validateConfirm(string $erpRefundId, array $payload): void
    {
        $errors = $this->erpRefundIdErrors($erpRefundId);
        if (!is_string($payload['transaction_number'] ?? null) || trim($payload['transaction_number']) === '') {
            $errors[] = 'transaction_number is missing';
        }
        if (!$this->isDate($payload['transaction_date'] ?? null)) {
            $errors[] = 'transaction_date is not a valid date';
        }

        $this->throwIfAny('Confirm Refund', $errors);
    }

    /**
     * Validate the credit-note identifier used in a Read Status or Confirm URL.
     *
     * @param string $erpRefundId
     * @return void
     * @throws ValidationException
     */
    public function validateErpRefundId(string $erpRefundId): void
    {
        $this->throwIfAny('Read Refund Status', $this->erpRefundIdErrors($erpRefundId));
    }

    /**
     * Whether a value is a non-negative amount with exactly four decimals.
     *
     * @param string $erpRefundId
     * @return string[]
     */
    private function erpRefundIdErrors(string $erpRefundId): array
    {
        return preg_match(self::ERP_REFUND_ID, $erpRefundId)
            ? []
            : [sprintf('erp_refund_id "%s" is not a valid ERP identifier', $erpRefundId)];
    }

    /**
     * Whether a value is a real calendar date in Y-m-d form.
     *
     * @param mixed $value
     * @return bool
     */
    private function isMoney(mixed $value): bool
    {
        return is_string($value) && preg_match(self::MONEY, $value) === 1;
    }

    /**
     * Whether the ERP accepts this business tax code.
     *
     * @param mixed $value
     * @return bool
     */
    private function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Throw one exception listing every problem found.
     *
     * @param string $code
     * @return bool
     */
    private function isKnownTaxCode(string $code): bool
    {
        try {
            $this->taxCodeResolver->assertKnown($code);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        return true;
    }

    /**
     * Throw one exception that lists every problem found.
     *
     * @param string $operation
     * @param string[] $errors
     * @return void
     * @throws ValidationException
     */
    private function throwIfAny(string $operation, array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException(
                __('The %1 request was not sent because it is invalid: %2.', $operation, implode('; ', $errors))
            );
        }
    }
}
