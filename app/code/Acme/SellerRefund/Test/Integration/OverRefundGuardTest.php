<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Exception\ValidationException;
use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * BR-03 regression: a seller line that has already been partly refunded can only be refunded
 * up to its remaining quantity. Uses seed order SR-ORD-1003, where SELLER-YEL-04 has 3 units
 * ordered and 2 already refunded, so exactly 1 remains.
 */
class OverRefundGuardTest extends DbTransactionTestCase
{
    private const ORDER = 'SR-ORD-1003';
    private const SKU = 'SELLER-YEL-04';

    public function testRequestAboveRemainingQuantityIsRejectedAndNothingIsWritten(): void
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId(self::ORDER);
        $orderId = (int) $order->getEntityId();
        $lineId = $this->lineIdForSku($seed, $order);
        $before = $this->rowCounts($orderId);

        try {
            $this->get(RefundProcessor::class)->submit($orderId, $seed->partialSubmission($lineId, '2'));
            self::fail('A request for 2 units with only 1 remaining must be rejected.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('exceeds the remaining refundable quantity', $e->getMessage());
        }

        self::assertSame($before, $this->rowCounts($orderId), 'No refund, item or outbox row may be written.');
    }

    public function testRequestAtRemainingQuantityIsAccepted(): void
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId(self::ORDER);
        $lineId = $this->lineIdForSku($seed, $order);

        $refund = $this->get(RefundProcessor::class)->submit(
            (int) $order->getEntityId(),
            $seed->partialSubmission($lineId, '1')
        );

        self::assertGreaterThan(0, (int) $refund->getEntityId());
    }

    /**
     * Resolve the order item id of the partly refunded seller line.
     *
     * @param SeedOrders $seed
     * @param OrderInterface $order
     * @return int
     */
    private function lineIdForSku(SeedOrders $seed, OrderInterface $order): int
    {
        foreach ($seed->sellerLines($order) as $itemId => $item) {
            if ($item->getSku() === self::SKU) {
                return (int) $itemId;
            }
        }

        self::fail(sprintf('Seed order %s has no %s line.', self::ORDER, self::SKU));
    }

    /**
     * Count the refund records for the order and their dependent rows.
     *
     * @param int $orderId
     * @return array<string, int>
     */
    private function rowCounts(int $orderId): array
    {
        $resource = $this->get(ResourceConnection::class);
        $connection = $this->connection();
        $refundIds = $connection->select()
            ->from($resource->getTableName('mp_refund'), ['entity_id'])
            ->where('order_id = ?', $orderId);

        $count = function (string $table, string $column) use ($resource, $connection, $refundIds): int {
            return (int) $connection->fetchOne(
                $connection->select()
                    ->from($resource->getTableName($table), ['COUNT(*)'])
                    ->where($column . ' IN (?)', $refundIds)
            );
        };

        return [
            'mp_refund' => (int) $connection->fetchOne(
                $connection->select()
                    ->from($resource->getTableName('mp_refund'), ['COUNT(*)'])
                    ->where('order_id = ?', $orderId)
            ),
            'mp_refund_item' => $count('mp_refund_item', 'refund_id'),
            'mp_refund_outbox' => $count('mp_refund_outbox', 'refund_id'),
        ];
    }
}
