<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Block\Order\RefundDetails;
use Acme\SellerRefund\Model\Email\RefundConfirmationSender;
use Acme\SellerRefund\Model\Export\RefundSyncExporter;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Acme\SellerRefund\Block\Order\RefundHistory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * On a pure-seller order every presentation and export surface derives its figures from the
 * calculator, so the receipt, the storefront order-details block, the order-history block,
 * the downstream sync export and the confirmation email must agree to the last unit.
 */
class SurfacesAgreeOnPureSellerOrderTest extends DbTransactionTestCase
{
    public function testAllSurfacesAgreeOnRefundFigures(): void
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId('SR-ORD-1001');
        $orderId = (int) $order->getEntityId();

        $refund = $this->get(RefundProcessor::class)->submit($orderId, $seed->fullSubmission($order));
        $refundId = (int) $refund->getEntityId();

        $repository = $this->get(RefundRepositoryInterface::class);
        $stored = $repository->getById($refundId);
        $fullOrder = $this->get(OrderRepositoryInterface::class)->get($orderId);

        // Baseline: the calculator's own figures for this snapshot.
        $expected = $this->get(RefundTotalCalculator::class)
            ->fromSnapshot($stored, $repository->getItems($refundId), $fullOrder)
            ->toArray()['refund'];

        // Surface 1: the reissued receipt.
        $receipt = $this->get(RefundReceipt::class)->buildData($stored);
        self::assertSame($expected, $receipt['refund'], 'Receipt figures diverge.');

        // Surface 2: the downstream sync export.
        $sync = $this->get(RefundSyncExporter::class)->export($stored);
        self::assertSame($expected, $sync['refund'], 'Sync export figures diverge.');

        // Surface 3: the storefront order-details block.
        $registry = $this->get(Registry::class);
        $registry->unregister('current_order');
        $registry->register('current_order', $fullOrder);
        $details = $this->create(RefundDetails::class);
        self::assertSame($expected, $this->figuresForRefund($details->getRefundViews(), $refundId));

        // Surface 4: the active customer order-history collection.
        $this->get(CustomerSession::class)->setCustomerId((int) $order->getCustomerId());
        $history = $this->create(RefundHistory::class)->getRefunds();
        self::assertNotNull($history);
        $listedRefund = $history->getItemById($refundId);
        self::assertNotNull($listedRefund, 'The refund must appear in customer history.');
        self::assertSame((string) $order->getIncrementId(), $listedRefund->getData('order_increment_id'));
        self::assertSame($expected, [
            'subtotal' => $listedRefund->getSubtotalAmount(),
            'shipping' => $listedRefund->getShippingAmount(),
            'tax' => $listedRefund->getTaxAmount(),
            'grand_total' => $listedRefund->getGrandTotal(),
        ], 'Order-history snapshot figures diverge.');

        // Surface 5: the confirmation email's data (prepared vars, before rendering).
        self::assertSame($expected, $this->emailRefundTotals($stored), 'Email figures diverge.');
    }

    /**
     * @param array<int, array{refund: RefundInterface, figures: RefundFigures}> $views
     *
     * @return array<string, string>
     */
    private function figuresForRefund(array $views, int $refundId): array
    {
        foreach ($views as $view) {
            if ((int) $view['refund']->getEntityId() === $refundId) {
                return $view['figures']->toArray()['refund'];
            }
        }

        self::fail('The refund was not present among the order-details views.');
    }

    /**
     * @return array<string, string>
     */
    private function emailRefundTotals(RefundInterface $refund): array
    {
        $sender = $this->get(RefundConfirmationSender::class);
        $method = new \ReflectionMethod($sender, 'prepare');
        $method->setAccessible(true);
        /** @var array{0: OrderInterface, 1: array<string, mixed>, 2: int} $prepared */
        $prepared = $method->invoke($sender, $refund);

        return $prepared[1]['refund_totals'];
    }
}
