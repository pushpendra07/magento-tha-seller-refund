<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Acme\SellerRefund\Model\Submission\RefundSubmission;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * The reissued receipt on a mixed order (seller lines at 10% and 8% plus a first-party line).
 *
 * The receipt is the qualified tax invoice: its header must be the seller figures every other
 * surface shows, its refunded-before history must not change once issued, and the per-rate tax
 * and ex-tax amount must actually be printed (FRD 10.4).
 */
class ReceiptOnMixedOrderTest extends DbTransactionTestCase
{
    private const ORDER = 'SR-ORD-1002';

    /**
     * Header from the shared calculator: seller lines only (1,200 + 1,600 + tax 248 = 3,048),
     * not the whole order (5,248 with the first-party line).
     *
     * @return void
     */
    public function testHeaderMatchesTheOtherSurfaces(): void
    {
        [$refund] = $this->refundBothSellerLines();
        $data = $this->get(RefundReceipt::class)->buildData($refund);

        $items = $this->get(RefundRepositoryInterface::class)->getItems((int) $refund->getEntityId());
        $order = $this->get(OrderRepositoryInterface::class)->get($refund->getOrderId());
        $figures = $this->get(RefundTotalCalculator::class)->fromSnapshot($refund, $items, $order);

        self::assertSame('3048.0000', $data['pre_refund']['grand_total']);
        self::assertSame($figures->preRefundGrandTotal, $data['pre_refund']['grand_total']);
        self::assertSame($figures->refundGrandTotal, $data['refund']['grand_total']);
    }

    /**
     * Refunded-before is the quantity stored on the item at submission: it excludes this refund
     * and stays the same when the receipt is regenerated later.
     *
     * @return void
     */
    public function testRefundedBeforeIsTheStoredSnapshot(): void
    {
        [$refund, $redLineId] = $this->refundBothSellerLines();
        $items = $this->get(RefundRepositoryInterface::class)->getItems((int) $refund->getEntityId());
        $stored = [];
        foreach ($items as $item) {
            $stored[(int) $item->getOrderItemId()] = (string) $item->getQtyRefundedBefore();
        }

        $lines = $this->get(RefundReceipt::class)->buildData($refund)['lines'];

        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            self::assertSame($stored[(int) $line['order_item_id']], $line['refunded_before']);
        }
        $red = array_values(array_filter($lines, static fn (array $l): bool => $l['order_item_id'] === $redLineId));
        self::assertSame('0.0000', $red[0]['refunded_before'], 'The refund must not count itself as before.');
    }

    /**
     * Receipt data carries the ex-tax amount and stored tax groups, and renders as a PDF.
     *
     * @return void
     */
    public function testReceiptBuildsExTaxAndTaxByRateAndRendersPdf(): void
    {
        [$refund] = $this->refundBothSellerLines();
        $receipt = $this->get(RefundReceipt::class);
        $data = $receipt->buildData($refund);
        $pdf = $receipt->render($refund);

        self::assertNotSame('', $data['display']['refund']['ex_tax']);
        self::assertSame(['008', '010'], array_column($data['tax_groups'], 'code'));
        self::assertSame('2000.0000', bcadd($data['refund']['subtotal'], $data['refund']['shipping'], 4));
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * Refund one unit of each seller line (SELLER-RED-01 at 10%, SELLER-BLU-02 at 8%).
     *
     * @return array{0: \Acme\SellerRefund\Api\Data\RefundInterface, 1: int}
     */
    private function refundBothSellerLines(): array
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId(self::ORDER);
        $ids = [];
        foreach ($seed->sellerLines($order) as $orderItemId => $item) {
            $ids[(string) $item->getSku()] = (int) $orderItemId;
        }
        self::assertArrayHasKey('SELLER-RED-01', $ids);
        self::assertArrayHasKey('SELLER-BLU-02', $ids);

        $refund = $this->get(RefundProcessor::class)->submit(
            (int) $order->getEntityId(),
            new RefundSubmission('defect', [$ids['SELLER-RED-01'] => '1', $ids['SELLER-BLU-02'] => '1'])
        );

        return [$refund, $ids['SELLER-RED-01']];
    }
}
