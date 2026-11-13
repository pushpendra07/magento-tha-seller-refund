<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Erp;

use Acme\SellerRefund\Model\Erp\CorrelationId;
use PHPUnit\Framework\TestCase;

/**
 * The id shown next to an integration error must be the one sent to the ERP (FRD 14).
 */
class CorrelationIdTest extends TestCase
{
    /**
     * Each ERP call maps to the X-Correlation-Id the client sends.
     *
     * @return void
     */
    public function testRecordedCallsMapToTheIdSentToTheErp(): void
    {
        $ids = new CorrelationId();

        self::assertSame('SR-20261112-000002:3', $ids->forApiCall('create', 'SR-20261112-000002', null, 3));
        self::assertSame('ERP-8000002:status', $ids->forApiCall('status', 'SR-20261112-000002', 'ERP-8000002', 1));
        self::assertSame('ERP-8000002:confirm', $ids->forApiCall('confirm', 'SR-20261112-000002', 'ERP-8000002', 2));
    }

    /**
     * No id is invented for a non-ERP event or before the ERP has assigned its refund id.
     *
     * @return void
     */
    public function testNoIdWithoutAnErpCall(): void
    {
        $ids = new CorrelationId();

        self::assertNull($ids->forApiCall(null, 'SR-20261112-000002', 'ERP-8000002', 1));
        self::assertNull($ids->forApiCall('erp_create', 'SR-20261112-000002', 'ERP-8000002', 1));
        self::assertNull($ids->forApiCall('status', 'SR-20261112-000002', null, 1));
    }
}
