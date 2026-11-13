<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Pdf\ReceiptDocumentFactory;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Acme\SellerRefund\Model\Source\RefundStatus;
use Acme\SellerRefund\Model\Total\RefundFigureLine;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The receipt must preserve the snapshot and group taxes without consulting later refunds.
 */
class RefundReceiptTest extends TestCase
{
    /**
     * @return void
     */
    public function testSnapshotHistoryAndMixedTaxGroupsArePreserved(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(7);
        $refund->method('getOrderId')->willReturn(100);
        $refund->method('getRefundNo')->willReturn('SR-20261113-000007');
        $refund->method('getCreatedAt')->willReturn('2026-11-12 23:00:00');
        $item = $this->createMock(RefundItemInterface::class);
        $item->method('getOrderItemId')->willReturn(1);
        $item->method('getQtyRefundedBefore')->willReturn('2.0000');
        $repository = $this->createMock(RefundRepositoryInterface::class);
        $repository->method('getItems')->with(7)->willReturn([$item]);
        $repository->expects(self::never())->method('getByOrderId');
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(2);
        $order->method('getIncrementId')->willReturn('ORDER-100');
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->with(100)->willReturn($order);
        $lines = [
            new RefundFigureLine(
                1,
                'A',
                'Seller A',
                '1.0000',
                '100.0000',
                '100.0000',
                '10.0000',
                '0.1000',
                '010',
                '11.0000',
                '121.0000'
            ),
            new RefundFigureLine(
                2,
                'B',
                'Seller B',
                '1.0000',
                '200.0000',
                '200.0000',
                '0.0000',
                '0.0800',
                '008',
                '16.0000',
                '216.0000'
            ),
            new RefundFigureLine(
                3,
                'C',
                'Seller C',
                '1.0000',
                '50.0000',
                '50.0000',
                '0.0000',
                '0.1000',
                '010',
                '5.0000',
                '55.0000'
            ),
        ];
        $calculator = $this->createMock(RefundTotalCalculator::class);
        $calculator->method('fromSnapshot')->with($refund, [$item], $order)->willReturn(new RefundFigures(
            '700.0000',
            '20.0000',
            '64.0000',
            '784.0000',
            '350.0000',
            '10.0000',
            '32.0000',
            '392.0000',
            $lines,
            'JPY',
            true
        ));
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->expects(self::once())->method('scopeDate')->with(2, '2026-11-12 23:00:00', true)
            ->willReturn(new \DateTime('2026-11-13'));
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->method('format')->willReturnCallback(
            function ($amount, $container, $precision, $store, $code): string {
                self::assertFalse($container);
                self::assertSame(0, $precision);
                self::assertSame(2, $store);
                self::assertSame('JPY', $code);

                return "\u{FFE5}" . $amount;
            }
        );
        $receipt = new RefundReceipt(
            $repository,
            $orders,
            $calculator,
            $timezone,
            $this->createMock(ReceiptDocumentFactory::class),
            $currency,
            $this->createMock(RefundStatus::class)
        );
        $data = $receipt->buildData($refund);

        self::assertSame('392.0000', $data['refund']['grand_total']);
        self::assertSame('784.0000', $data['pre_refund']['grand_total']);
        self::assertSame('2.0000', $data['lines'][0]['refunded_before']);
        self::assertSame('2026-11-13', $data['refund_date']);
        self::assertSame([
            ['code' => '008', 'rate' => '0.0800', 'taxable' => '200.0000', 'tax' => '16.0000'],
            ['code' => '010', 'rate' => '0.1000', 'taxable' => '160.0000', 'tax' => '16.0000'],
        ], $data['tax_groups']);
        self::assertSame("\u{00A5}360.0000", $data['display']['refund']['ex_tax']);
    }
}
