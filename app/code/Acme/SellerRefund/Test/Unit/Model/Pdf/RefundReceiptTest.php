<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Acme\SellerRefund\Model\Total\RefundFigureLine;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Receipt layout with two tax rates and shipping: every figure comes from the calculator, tax is
 * grouped on the stored rate and code, and the groups and ex-tax row are printed.
 */
class RefundReceiptTest extends TestCase
{
    /**
     * A JPY 333 line at 10% carries JPY 33 tax after rounding; it must still group as 10%.
     *
     * @return void
     */
    public function testGroupsTaxOnTheStoredRateAndPrintsIt(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(42);
        $refund->method('getOrderId')->willReturn(7);
        $refund->method('getRefundNo')->willReturn('SR-20260901-000042');
        $refund->method('getCreatedAt')->willReturn('2026-09-01 10:00:00');

        $items = [$this->item(101, '1.0000'), $this->item(102, '0.0000'), $this->item(103, '2.0000')];
        $repository = $this->createMock(RefundRepositoryInterface::class);
        $repository->method('getItems')->with(42)->willReturn($items);
        $order = $this->createMock(OrderInterface::class);
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->with(7)->willReturn($order);

        $lines = [
            $this->line(101, '1000.0000', '50.0000', '0.1000', '010', '105.0000'),
            $this->line(102, '800.0000', '0.0000', '0.0800', '008', '64.0000'),
            $this->line(103, '333.0000', '0.0000', '0.1000', '010', '33.0000'),
        ];
        $figures = new RefundFigures(
            preRefundSubtotal: '5000.0000',
            preRefundShipping: '100.0000',
            preRefundTax: '500.0000',
            preRefundGrandTotal: '5600.0000',
            refundSubtotal: '2133.0000',
            refundShipping: '50.0000',
            refundTax: '202.0000',
            refundGrandTotal: '2385.0000',
            lines: $lines,
            currency: 'JPY',
            partial: true
        );
        $calculator = $this->createMock(RefundTotalCalculator::class);
        $calculator->method('fromSnapshot')->with($refund, $items, $order)->willReturn($figures);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-09-01 10:00:00'));

        $receipt = new RefundReceipt($repository, $orders, $calculator, $timezone);
        $data = $receipt->buildData($refund);
        $pdf = $receipt->render($refund);

        self::assertTrue($data['is_partial']);
        self::assertSame('5600.0000', $data['pre_refund']['grand_total']);
        self::assertSame('2183.0000', $data['refund_ex_tax']);
        self::assertSame([
            ['code' => '008', 'rate' => '0.0800', 'taxable' => '800.0000', 'tax' => '64.0000'],
            ['code' => '010', 'rate' => '0.1000', 'taxable' => '1383.0000', 'tax' => '138.0000'],
        ], $data['tax_groups']);
        self::assertSame(['1.0000', '0.0000', '2.0000'], array_column($data['lines'], 'refunded_before'));

        self::assertStringContainsString('Ex-tax Amount: JPY 2,183', $pdf);
        self::assertStringContainsString('taxable JPY 1,383  tax JPY 138', $pdf);
        self::assertStringContainsString('taxable JPY 800  tax JPY 64', $pdf);
        self::assertStringContainsString('refunded before: 2', $pdf);
    }

    /**
     * One refunded line of quantity 1 as the calculator returns it.
     *
     * @param int $orderItemId
     * @param string $row
     * @param string $shipping
     * @param string $rate
     * @param string $code
     * @param string $tax
     * @return RefundFigureLine
     */
    private function line(
        int $orderItemId,
        string $row,
        string $shipping,
        string $rate,
        string $code,
        string $tax
    ): RefundFigureLine {
        return new RefundFigureLine(
            orderItemId: $orderItemId,
            sku: 'SKU-' . $orderItemId,
            productName: 'Item ' . $orderItemId,
            qtyRefund: '1.0000',
            unitPrice: $row,
            rowAmount: $row,
            shippingAmount: $shipping,
            taxRate: $rate,
            taxCode: $code,
            taxAmount: $tax,
            grandTotal: bcadd(bcadd($row, $shipping, 4), $tax, 4)
        );
    }

    /**
     * A refund item with its stored refunded-before quantity.
     *
     * @param int $orderItemId
     * @param string $refundedBefore
     * @return RefundItemInterface
     */
    private function item(int $orderItemId, string $refundedBefore): RefundItemInterface
    {
        $item = $this->createMock(RefundItemInterface::class);
        $item->method('getOrderItemId')->willReturn($orderItemId);
        $item->method('getQtyRefundedBefore')->willReturn($refundedBefore);

        return $item;
    }
}
