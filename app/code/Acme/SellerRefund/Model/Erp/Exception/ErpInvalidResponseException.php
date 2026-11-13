<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Erp\Exception;

/**
 * The ERP answered 2xx, but the body is not the documented success response: invalid JSON, a
 * missing or different erp_refund_id, or an unexpected status.
 *
 * It is retried like a transient error. Create (keyed on refund_no) and Confirm (keyed on
 * erp_refund_id) are idempotent upstream, so a retry returns the same credit note rather than
 * a second one; if the answer stays wrong, the job runs out of attempts and needs an operator.
 */
class ErpInvalidResponseException extends ErpTransientException
{
    /**
     * @inheritdoc
     */
    public function getErrorCode(): string
    {
        return 'invalid_response';
    }
}
