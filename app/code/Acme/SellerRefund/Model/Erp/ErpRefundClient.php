<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Erp;

use Acme\SellerRefund\Model\Config;
use Acme\SellerRefund\Model\Erp\Exception\ErpBusinessException;
use Acme\SellerRefund\Model\Erp\Exception\ErpConflictException;
use Acme\SellerRefund\Model\Erp\Exception\ErpInvalidResponseException;
use Acme\SellerRefund\Model\Erp\Exception\ErpTransientException;
use Acme\SellerRefund\Model\ResourceModel\Refund as RefundResource;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Thin transport over the three ERP Refund API operations. Classifies every outcome as
 * success, transient (retry), conflict, or a terminal business rejection.
 */
class ErpRefundClient
{
    private const CREATE_PATH = '/erp-api/v1/refunds';

    private const STATUS_PENDING = 'refund-pending';

    private const STATUS_CONFIRMED = 'refund-confirmed';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly RefundResource $refundResource,
        private readonly CorrelationId $correlationId
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, int|RequestKey $attempt = 1): CreateResult
    {
        $refundNo = (string) ($payload['refund_no'] ?? '');
        $attemptNo = $attempt instanceof RequestKey ? $attempt->getAttemptNo() : $attempt;
        $requestKey = $attempt instanceof RequestKey
            ? $attempt->getValue()
            : sprintf('%s:%02d', $refundNo, max(1, $attemptNo));
        $curl = $this->newCurl($this->correlationId->forCreate($refundNo, $attemptNo));
        $curl->addHeader('X-Request-Id', $requestKey);
        $uri = $this->config->erpBaseUrl() . self::CREATE_PATH;

        $this->send($curl, $uri, $payload);
        $this->classify($curl, false);

        $body = $this->decode($curl->getBody());
        $erpId = $body['erp_refund_id'] ?? null;
        $validId = is_string($erpId) && strlen($erpId) <= 64 && preg_match('/^ERP-[0-9]+$/D', $erpId) === 1;
        $this->assertSuccessBody('Create Refund', $curl, $validId, $body, self::STATUS_PENDING);

        return new CreateResult($erpId, (string) $body['status']);
    }

    /**
     * @return array<string, mixed>
     */
    public function readStatus(string $erpId): array
    {
        $curl = $this->newCurl($this->correlationId->forStatusCheck($erpId));
        $uri = $this->config->erpBaseUrl() . self::CREATE_PATH . '/' . rawurlencode($erpId);

        try {
            $curl->get($uri);
        } catch (\Exception $e) {
            throw new ErpTransientException('The ERP status read failed at the transport level.', 0, null, $e);
        }

        $this->classify($curl, true);

        // The status itself is judged by the caller (read-before-confirm); here only the
        // identity of the credit note is checked.
        $body = $this->decode($curl->getBody());
        $this->assertSuccessBody(
            'Read Refund Status',
            $curl,
            (string) ($body['erp_refund_id'] ?? '') === $erpId,
            $body,
            null
        );

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function confirm(string $erpId, array $payload): array
    {
        $curl = $this->newCurl($this->correlationId->forConfirm($erpId));
        $uri = $this->config->erpBaseUrl() . self::CREATE_PATH . '/' . rawurlencode($erpId) . '/confirm';

        $this->send($curl, $uri, $payload);
        $this->classify($curl, false);

        $body = $this->decode($curl->getBody());
        $this->assertSuccessBody(
            'Confirm Refund',
            $curl,
            (string) ($body['erp_refund_id'] ?? '') === $erpId,
            $body,
            self::STATUS_CONFIRMED
        );

        return $body;
    }

    /**
     * Fresh ledger check used by the create-idempotency guard: has a create for this
     * refund_no already succeeded upstream?
     */
    public function hasSucceededCreate(string $refundNo): bool
    {
        return $this->refundResource->isCreateSucceeded($refundNo);
    }

    private function newCurl(string $correlationId): Curl
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $this->config->connectTimeout());
        $curl->setTimeout($this->config->timeout());
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('X-Correlation-Id', $correlationId);

        return $curl;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(Curl $curl, string $uri, array $payload): void
    {
        try {
            $curl->post($uri, $this->encode($payload));
        } catch (\Exception $e) {
            throw new ErpTransientException('The ERP call failed at the transport level.', 0, null, $e);
        }
    }

    private function classify(Curl $curl, bool $isStatusRead): void
    {
        $status = $curl->getStatus();

        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status === 409) {
            throw new ErpConflictException('The ERP reported a conflicting refund state.');
        }

        if ($status === 408 || $status === 429 || $status >= 500 || ($isStatusRead && $status === 404)) {
            throw new ErpTransientException(
                sprintf('The ERP returned a transient error (HTTP %d).', $status),
                $status,
                $this->retryAfter($curl)
            );
        }

        $body = $this->decode($curl->getBody());
        throw new ErpBusinessException(
            sprintf('The ERP rejected the refund (HTTP %d).', $status),
            (string) ($body['error_code'] ?? '')
        );
    }

    /**
     * Accept a 2xx only when its body is the documented response for the operation.
     *
     * Otherwise local state must not advance (FRD 9).
     *
     * @param string $operation
     * @param Curl $curl
     * @param bool $idMatches
     * @param array $body
     * @param string|null $expectedStatus
     * @return void
     * @throws ErpInvalidResponseException
     */
    private function assertSuccessBody(
        string $operation,
        Curl $curl,
        bool $idMatches,
        array $body,
        ?string $expectedStatus
    ): void {
        $statusMatches = $expectedStatus === null
            ? (string) ($body['status'] ?? '') !== ''
            : ($body['status'] ?? null) === $expectedStatus;
        if ($idMatches && $statusMatches) {
            return;
        }

        throw new ErpInvalidResponseException(
            sprintf(
                'The ERP answered %s with HTTP %d but not with the expected credit note and status.',
                $operation,
                $curl->getStatus()
            ),
            $curl->getStatus()
        );
    }

    private function retryAfter(Curl $curl): ?int
    {
        $headers = $curl->getHeaders();
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after' && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
