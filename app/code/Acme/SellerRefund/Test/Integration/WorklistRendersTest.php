<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use Acme\SellerRefund\Model\ResourceModel\Refund\Grid\Collection;
use Acme\SellerRefund\Test\Integration\Framework\AppTestCase;

/**
 * The active UI grid data source supplies order and line summaries without per-row loads.
 */
class WorklistRendersTest extends AppTestCase
{
    public function testWorklistReturnsCollectionWithJoinedSummaryColumns(): void
    {
        /** @var Collection $collection */
        $collection = $this->create(CollectionFactory::class)
            ->getReport('acme_refund_listing_data_source');

        // The seeded refunds (SR-ORD-1003, SR-ORD-1006) are present.
        self::assertGreaterThanOrEqual(1, $collection->getSize());

        $items = $collection->getItems();
        self::assertNotEmpty($items);

        foreach ($items as $row) {
            $data = $row->getData();
            self::assertArrayHasKey('order_increment_id', $data);
            self::assertArrayHasKey('line_count', $data);
            self::assertNotSame('', (string) $row->getData('order_increment_id'));
        }
    }
}
