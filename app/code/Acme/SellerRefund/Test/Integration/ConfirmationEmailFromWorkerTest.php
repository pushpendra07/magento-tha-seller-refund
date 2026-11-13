<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Email\RefundConfirmationSender;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;

/**
 * The confirmation email is sent by the outbox worker, which runs in the crontab area (cron and
 * `acme:refund:outbox:work`). It must render there, not only in a frontend request.
 */
class ConfirmationEmailFromWorkerTest extends DbTransactionTestCase
{
    public function testEmailRendersFromTheCrontabArea(): void
    {
        // The seeded, ERP-confirmed refund on SR-ORD-1003 always exists after seeding.
        $order = $this->get(SeedOrders::class)->loadByIncrementId('SR-ORD-1003');
        $resource = $this->get(ResourceConnection::class);
        $refundId = (int) $this->connection()->fetchOne(
            $this->connection()->select()
                ->from($resource->getTableName('mp_refund'), ['entity_id'])
                ->where('order_id = ?', (int) $order->getEntityId())
                ->order('entity_id ASC')
                ->limit(1)
        );
        self::assertGreaterThan(0, $refundId, 'Seed refund on SR-ORD-1003 is missing.');
        $refund = $this->get(RefundRepositoryInterface::class)->getById($refundId);
        $sender = $this->get(RefundConfirmationSender::class);

        $html = $this->get(State::class)->emulateAreaCode(
            Area::AREA_CRONTAB,
            static fn (): string => $sender->render($refund)
        );

        self::assertStringContainsString((string) $refund->getRefundNo(), $html);
    }
}
