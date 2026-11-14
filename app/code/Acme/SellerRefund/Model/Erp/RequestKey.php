<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Erp;

/**
 * Identity of one ERP request attempt, for tracing only.
 *
 * Sent as the X-Request-Id header and stored on the attempt's audit event, so ERP-side entries
 * for each attempt can be told apart. It is never the idempotency key: Create always sends the
 * stored refund_no unchanged (BR-07), which the ERP deduplicates on.
 *
 * Create instances through the generated RequestKeyFactory.
 */
class RequestKey
{
    /**
     * @param string $refundNo
     * @param int $attemptNo
     */
    public function __construct(
        private readonly string $refundNo,
        private readonly int $attemptNo
    ) {
    }

    /**
     * The refund number the request belongs to.
     *
     * @return string
     */
    public function getRefundNo(): string
    {
        return $this->refundNo;
    }

    /**
     * The 1-based attempt number.
     *
     * @return int
     */
    public function getAttemptNo(): int
    {
        return $this->attemptNo;
    }

    /**
     * Request identifier for this attempt, for example "SR-20261112-000123:02".
     *
     * @return string
     */
    public function getValue(): string
    {
        return sprintf('%s:%02d', $this->refundNo, max(1, $this->attemptNo));
    }
}
