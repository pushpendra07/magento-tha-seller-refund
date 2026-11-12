<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Total;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Tax\LineTaxRate;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
use DateTimeInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * The single source of truth for refund money. Every presentation surface and the ERP
 * payload are fed from here. Core (first-party) lines are ignored entirely; only seller
 * lines are considered.
 *
 * Consumption tax is computed store-side at the original order rate:
 *   tax_amount = (unit_price * qty_refund + line_shipping) * tax_rate.
 * A partial refund refunds no shipping; a full refund refunds the remaining seller
 * shipping, allocated across seller lines in proportion to their ex-tax row amount.
 */
class RefundTotalCalculator
{
    private const SCALE = 4;
    private const ZERO = '0.0000';

    public function __construct(
        private readonly SellerLineResolver $sellerLineResolver,
        private readonly TaxCodeResolver $taxCodeResolver,
        private readonly LineTaxRate $lineTaxRate
    ) {
    }

    /**
     * @param array<int, string> $qtyByItem   order_item_id => requested qty
     * @param array<int|string, string> $priorByItem order_item_id => already refunded qty
     */
    public function fromSelection(
        OrderInterface $order,
        array $qtyByItem,
        array $priorByItem,
        DateTimeInterface $at
    ): RefundFigures {
        $sellerLines = $this->sellerLineResolver->sellerLines($order);
        $partial = !$this->isFullSelection($sellerLines, $qtyByItem, $priorByItem);

        $selected = [];
        foreach ($qtyByItem as $orderItemId => $qty) {
            $orderItemId = (int) $orderItemId;
            if (!isset($sellerLines[$orderItemId]) || bccomp($this->num($qty), self::ZERO, self::SCALE) <= 0) {
                continue;
            }
            $selected[$orderItemId] = $this->num($qty);
        }

        // Row amounts drive the shipping allocation weights.
        $rowAmounts = [];
        foreach ($selected as $orderItemId => $qty) {
            $unitPrice = $this->num($sellerLines[$orderItemId]->getPrice());
            $rowAmounts[$orderItemId] = bcmul($unitPrice, $qty, self::SCALE);
        }

        $refundShipping = $partial ? self::ZERO : $this->sellerShippingBase($order, $sellerLines);
        $shippingShares = $this->allocate($refundShipping, $rowAmounts);

        $lines = [];
        foreach ($selected as $orderItemId => $qty) {
            $item = $sellerLines[$orderItemId];
            $unitPrice = $this->num($item->getPrice());
            $rowAmount = $rowAmounts[$orderItemId];
            $shipping = $shippingShares[$orderItemId] ?? self::ZERO;
            $code = $this->taxCodeResolver->toBusinessCode((int) $item->getData('mp_tax_class'));
            $rate = $this->lineTaxRate->forItem($item);
            $taxable = bcadd($rowAmount, $shipping, self::SCALE);
            $taxAmount = bcmul($taxable, $rate, self::SCALE);
            $grandTotal = bcadd($taxable, $taxAmount, self::SCALE);

            $lines[] = new RefundFigureLine(
                $orderItemId,
                (string) $item->getSku(),
                (string) $item->getName(),
                $qty,
                $unitPrice,
                $rowAmount,
                $shipping,
                $rate,
                $code,
                $taxAmount,
                $grandTotal
            );
        }

        return $this->assemble($lines, $this->preRefund($order), $this->currency($order), $partial);
    }

    /**
     * @param RefundItemInterface[] $items
     */
    public function fromSnapshot(RefundInterface $refund, array $items, OrderInterface $order): RefundFigures
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = new RefundFigureLine(
                $item->getOrderItemId(),
                $item->getSku(),
                $item->getProductName(),
                $item->getQtyRefund(),
                $item->getUnitPrice(),
                $item->getRowAmount(),
                $item->getShippingAmount(),
                $item->getTaxRate(),
                $item->getTaxCode(),
                $item->getTaxAmount(),
                $item->getGrandTotal()
            );
        }

        $partial = $refund->getRefundType() === RefundInterface::TYPE_PARTIAL;

        return $this->assemble($lines, $this->preRefund($order), $refund->getCurrencyCode(), $partial);
    }

    /**
     * @param RefundFigureLine[] $lines
     * @param array{subtotal:string, shipping:string, tax:string, grand_total:string} $preRefund
     */
    private function assemble(array $lines, array $preRefund, string $currency, bool $partial): RefundFigures
    {
        $subtotal = self::ZERO;
        $shipping = self::ZERO;
        $tax = self::ZERO;
        $grandTotal = self::ZERO;
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, $line->rowAmount, self::SCALE);
            $shipping = bcadd($shipping, $line->shippingAmount, self::SCALE);
            $tax = bcadd($tax, $line->taxAmount, self::SCALE);
            $grandTotal = bcadd($grandTotal, $line->grandTotal, self::SCALE);
        }

        return new RefundFigures(
            $preRefund['subtotal'],
            $preRefund['shipping'],
            $preRefund['tax'],
            $preRefund['grand_total'],
            $subtotal,
            $shipping,
            $tax,
            $grandTotal,
            $lines,
            $currency,
            $partial
        );
    }

    /**
     * Pre-refund seller totals: every seller line at its ordered quantity with the full
     * seller shipping allocated by ex-tax row amount.
     *
     * @return array{subtotal:string, shipping:string, tax:string, grand_total:string}
     */
    private function preRefund(OrderInterface $order): array
    {
        $sellerLines = $this->sellerLineResolver->sellerLines($order);

        $rowAmounts = [];
        foreach ($sellerLines as $orderItemId => $item) {
            $rowAmounts[$orderItemId] = bcmul(
                $this->num($item->getPrice()),
                $this->num($item->getQtyOrdered()),
                self::SCALE
            );
        }

        $shipping = $this->sellerShippingBase($order, $sellerLines);
        $shippingShares = $this->allocate($shipping, $rowAmounts);

        $subtotal = self::ZERO;
        $tax = self::ZERO;
        foreach ($sellerLines as $orderItemId => $item) {
            $rowAmount = $rowAmounts[$orderItemId];
            $share = $shippingShares[$orderItemId] ?? self::ZERO;
            $code = $this->taxCodeResolver->toBusinessCode((int) $item->getData('mp_tax_class'));
            $rate = $this->lineTaxRate->forItem($item);
            $subtotal = bcadd($subtotal, $rowAmount, self::SCALE);
            $tax = bcadd($tax, bcmul(bcadd($rowAmount, $share, self::SCALE), $rate, self::SCALE), self::SCALE);
        }

        $grandTotal = bcadd(bcadd($subtotal, $shipping, self::SCALE), $tax, self::SCALE);

        return [
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'tax' => $tax,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * @param array<int, \Magento\Sales\Api\Data\OrderItemInterface> $sellerLines
     * @param array<int, string> $qtyByItem
     * @param array<int|string, string> $priorByItem
     */
    private function isFullSelection(array $sellerLines, array $qtyByItem, array $priorByItem): bool
    {
        foreach ($sellerLines as $orderItemId => $item) {
            $prior = $this->num($priorByItem[$orderItemId] ?? '0');
            $remaining = bcsub($this->num($item->getQtyOrdered()), $prior, self::SCALE);
            $requested = $this->num($qtyByItem[$orderItemId] ?? '0');
            if (bccomp($requested, $remaining, self::SCALE) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Seller share of the order's shipping (FRD section 11).
     *
     * The order stores one shipping amount for all lines. On a mixed order that amount also
     * covers first-party items, so the seller share is shipping x seller ex-tax rows / order
     * subtotal. It never exceeds the order's shipping and is zero when it cannot be derived.
     *
     * @param OrderInterface $order
     * @param array<int, \Magento\Sales\Api\Data\OrderItemInterface> $sellerLines
     * @return string
     */
    private function sellerShippingBase(OrderInterface $order, array $sellerLines): string
    {
        $orderShipping = $this->num($order->getShippingAmount());
        $orderSubtotal = $this->num($order->getSubtotal());
        if (bccomp($orderShipping, self::ZERO, self::SCALE) <= 0
            || bccomp($orderSubtotal, self::ZERO, self::SCALE) <= 0
        ) {
            return self::ZERO;
        }

        $sellerSubtotal = self::ZERO;
        foreach ($sellerLines as $item) {
            $sellerSubtotal = bcadd(
                $sellerSubtotal,
                bcmul($this->num($item->getPrice()), $this->num($item->getQtyOrdered()), self::SCALE),
                self::SCALE
            );
        }

        if (bccomp($sellerSubtotal, $orderSubtotal, self::SCALE) >= 0) {
            return $orderShipping;
        }

        return bcdiv(bcmul($orderShipping, $sellerSubtotal, 8), $orderSubtotal, self::SCALE);
    }

    private function currency(OrderInterface $order): string
    {
        $code = $order->getOrderCurrencyCode();

        return $code !== null && $code !== '' ? (string) $code : 'JPY';
    }

    /**
     * Proportional allocation of a total across integer-keyed weights, with the last key
     * absorbing any rounding remainder so the shares sum exactly to the total.
     *
     * @param array<int, string> $weights
     *
     * @return array<int, string>
     */
    private function allocate(string $total, array $weights): array
    {
        $shares = [];
        foreach ($weights as $key => $weight) {
            $shares[$key] = self::ZERO;
        }

        if ($weights === [] || bccomp($total, self::ZERO, self::SCALE) === 0) {
            return $shares;
        }

        $sumWeights = self::ZERO;
        foreach ($weights as $weight) {
            $sumWeights = bcadd($sumWeights, $weight, self::SCALE);
        }

        $keys = array_keys($weights);
        $lastKey = end($keys);

        if (bccomp($sumWeights, self::ZERO, self::SCALE) === 0) {
            $shares[$keys[0]] = $total;

            return $shares;
        }

        $allocated = self::ZERO;
        foreach ($weights as $key => $weight) {
            if ($key === $lastKey) {
                $shares[$key] = bcsub($total, $allocated, self::SCALE);
                continue;
            }
            $share = bcdiv(bcmul($total, $weight, self::SCALE + 4), $sumWeights, self::SCALE);
            $shares[$key] = $share;
            $allocated = bcadd($allocated, $share, self::SCALE);
        }

        return $shares;
    }

    private function num(mixed $value): string
    {
        return sprintf('%.4F', (float) $value);
    }
}
