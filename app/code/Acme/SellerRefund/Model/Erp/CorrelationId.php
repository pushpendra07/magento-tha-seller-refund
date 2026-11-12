<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Erp;

/**
 * The X-Correlation-Id sent with each ERP Refund API call.
 *
 * One place builds it, so the identifier shown next to an integration error in admin is the
 * one the ERP received and an operator can quote it to the ERP team (FRD 14).
 */
class CorrelationId
{
    public const API_CREATE = 'create';
    public const API_STATUS = 'status';
    public const API_CONFIRM = 'confirm';

    /**
     * Create Refund: the refund number and the attempt, for example "SR-20261112-000002:2".
     *
     * @param string $refundNo
     * @param int $attemptNo
     * @return string
     */
    public function forCreate(string $refundNo, int $attemptNo): string
    {
        return $refundNo . ':' . $attemptNo;
    }

    /**
     * Read Refund Status: the ERP refund id, for example "ERP-8000002:status".
     *
     * @param string $erpRefundId
     * @return string
     */
    public function forStatusCheck(string $erpRefundId): string
    {
        return $erpRefundId . ':status';
    }

    /**
     * Confirm Refund: the ERP refund id, for example "ERP-8000002:confirm".
     *
     * @param string $erpRefundId
     * @return string
     */
    public function forConfirm(string $erpRefundId): string
    {
        return $erpRefundId . ':confirm';
    }

    /**
     * The identifier of a recorded API call, or null when the event is not an ERP call.
     *
     * @param string|null $apiCode
     * @param string $refundNo
     * @param string|null $erpRefundId
     * @param int $attemptNo
     * @return string|null
     */
    public function forApiCall(?string $apiCode, string $refundNo, ?string $erpRefundId, int $attemptNo): ?string
    {
        $erpRefundId = (string) $erpRefundId;

        return match ($apiCode) {
            self::API_CREATE => $this->forCreate($refundNo, $attemptNo),
            self::API_STATUS => $erpRefundId !== '' ? $this->forStatusCheck($erpRefundId) : null,
            self::API_CONFIRM => $erpRefundId !== '' ? $this->forConfirm($erpRefundId) : null,
            default => null,
        };
    }
}
