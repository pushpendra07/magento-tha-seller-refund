<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Erp;

use Acme\SellerRefund\Model\Config;
use Acme\SellerRefund\Model\Erp\CorrelationId;
use Acme\SellerRefund\Model\Erp\ErpRefundClient;
use Acme\SellerRefund\Model\Erp\Exception\ErpInvalidResponseException;
use Acme\SellerRefund\Model\ResourceModel\Refund as RefundResource;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;

/**
 * A 2xx answer only counts as success when its body is the documented response (FRD 9);
 * anything else must not advance the refund.
 */
class ErpRefundClientTest extends TestCase
{
    /**
     * @return void
     */
    public function testDocumentedResponsesAreAccepted(): void
    {
        $result = $this->client('{"erp_refund_id":"ERP-8000123","status":"refund-pending"}')
            ->create(['refund_no' => 'SR-20261113-000001']);
        self::assertSame('ERP-8000123', $result->getErpRefundId());

        $status = $this->client('{"erp_refund_id":"ERP-8000123","status":"refund-pending"}')
            ->readStatus('ERP-8000123');
        self::assertSame('refund-pending', $status['status']);

        $confirmed = $this->client('{"erp_refund_id":"ERP-8000123","status":"refund-confirmed"}')
            ->confirm('ERP-8000123', ['bank_transaction_ref' => 'TXN-1']);
        self::assertSame('refund-confirmed', $confirmed['status']);
    }

    /**
     * @param string $operation
     * @param string $body
     * @return void
     * @dataProvider invalidSuccessBodyProvider
     */
    public function testUnexpectedSuccessBodyIsRetriedNotAccepted(string $operation, string $body): void
    {
        $client = $this->client($body);

        try {
            match ($operation) {
                'create' => $client->create(['refund_no' => 'SR-20261113-000001']),
                'status' => $client->readStatus('ERP-8000123'),
                'confirm' => $client->confirm('ERP-8000123', ['bank_transaction_ref' => 'TXN-1']),
            };
            self::fail('A malformed success response must not be accepted.');
        } catch (ErpInvalidResponseException $e) {
            self::assertSame('invalid_response', $e->getErrorCode());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidSuccessBodyProvider(): array
    {
        return [
            'create: invalid JSON' => ['create', 'OK'],
            'create: no credit note id' => ['create', '{"status":"refund-pending"}'],
            'create: unexpected status' => ['create', '{"erp_refund_id":"ERP-8000123","status":"refund-rejected"}'],
            'status: another credit note' => ['status', '{"erp_refund_id":"ERP-8000999","status":"refund-pending"}'],
            'status: empty body' => ['status', ''],
            'confirm: empty body' => ['confirm', ''],
            'confirm: not confirmed' => ['confirm', '{"erp_refund_id":"ERP-8000123","status":"refund-pending"}'],
            'confirm: another credit note' => [
                'confirm',
                '{"erp_refund_id":"ERP-8000999","status":"refund-confirmed"}',
            ],
        ];
    }

    /**
     * Builds a client whose ERP answers HTTP 200 with the given body.
     *
     * @param string $body
     * @return ErpRefundClient
     */
    private function client(string $body): ErpRefundClient
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn($body);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);
        $config = $this->createMock(Config::class);
        $config->method('erpBaseUrl')->willReturn('http://erp.test');
        $config->method('connectTimeout')->willReturn(2);
        $config->method('timeout')->willReturn(5);

        return new ErpRefundClient(
            $curlFactory,
            $config,
            $this->createMock(RefundResource::class),
            $this->createMock(CorrelationId::class)
        );
    }
}
