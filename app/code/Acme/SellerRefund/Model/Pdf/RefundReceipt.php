<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Total\RefundFigureLine;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Reissued receipt / qualified tax invoice for a refund.
 *
 * Every figure is taken from the stored refund snapshot through the calculator, so the receipt
 * agrees with the other presentation surfaces and the downstream export (REF-142). On top of
 * that it prints the refund's ex-tax amount and its consumption tax per rate, which the
 * qualified invoice needs (FRD 10.4). Labels are ASCII only.
 */
class RefundReceipt
{
    private const SCALE = 4;
    private const ZERO = '0.0000';

    /**
     * @param RefundRepositoryInterface $refundRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param RefundTotalCalculator $calculator
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RefundTotalCalculator $calculator,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * View data for the receipt. This is the harness inspection point.
     *
     * @param RefundInterface $refund
     * @return array<string, mixed>
     */
    public function buildData(RefundInterface $refund): array
    {
        $items = $this->refundRepository->getItems((int) $refund->getEntityId());
        $order = $this->orderRepository->get($refund->getOrderId());
        $figures = $this->calculator->fromSnapshot($refund, $items, $order);
        $refundedBefore = $this->refundedBefore($items);

        return [
            'refund_no' => $refund->getRefundNo(),
            'currency' => $figures->currency,
            'is_partial' => $figures->isPartial(),
            'refund_date' => $this->refundDate($refund),
            'pre_refund' => [
                'subtotal' => $figures->preRefundSubtotal,
                'shipping' => $figures->preRefundShipping,
                'tax' => $figures->preRefundTax,
                'grand_total' => $figures->preRefundGrandTotal,
            ],
            'refund' => [
                'subtotal' => $figures->refundSubtotal,
                'shipping' => $figures->refundShipping,
                'tax' => $figures->refundTax,
                'grand_total' => $figures->refundGrandTotal,
            ],
            'refund_ex_tax' => bcadd($figures->refundSubtotal, $figures->refundShipping, self::SCALE),
            'tax_groups' => $this->taxGroups($figures->lines),
            'lines' => array_map(
                static fn (RefundFigureLine $line): array => $line->toArray() + [
                    'refunded_before' => $refundedBefore[$line->orderItemId] ?? self::ZERO,
                ],
                $figures->lines
            ),
        ];
    }

    /**
     * Render the receipt as PDF bytes.
     *
     * @param RefundInterface $refund
     * @return string
     */
    public function render(RefundInterface $refund): string
    {
        $data = $this->buildData($refund);
        $currency = (string) $data['currency'];

        $pdf = new \Zend_Pdf();
        $page = new \Zend_Pdf_Page(\Zend_Pdf_Page::SIZE_A4);
        $pdf->pages[] = $page;
        $font = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA);
        $bold = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA_BOLD);

        $y = 800;
        $page->setFont($bold, 14);
        $y = $this->line($page, 'Refund Receipt / Qualified Invoice', $y, 18);

        $page->setFont($font, 10);
        $y = $this->line($page, 'Refund No: ' . $this->ascii((string) $data['refund_no']), $y);
        $y = $this->line($page, 'Refund Date: ' . $this->ascii((string) $data['refund_date']), $y);
        $y = $this->line($page, 'Refund Type: ' . ($data['is_partial'] ? 'Partial Refund' : 'Full Refund'), $y, 18);

        $page->setFont($bold, 11);
        $y = $this->line($page, 'Refunded Lines', $y, 14);
        $page->setFont($font, 9);
        foreach ((array) $data['lines'] as $lineData) {
            $text = sprintf(
                '%s  x%s  (refunded before: %s)  rate %s  ex-tax %s  tax %s  total %s',
                $this->ascii((string) $lineData['sku']),
                $this->qty((string) $lineData['qty_refund']),
                $this->qty((string) $lineData['refunded_before']),
                $this->percent((string) $lineData['tax_rate']),
                $this->money($lineData['row_amount'], $currency),
                $this->money($lineData['tax_amount'], $currency),
                $this->money($lineData['grand_total'], $currency)
            );
            $y = $this->line($page, $text, $y);
        }

        $y -= 8;
        $page->setFont($bold, 11);
        $y = $this->line($page, 'Pre-refund Total', $y, 14);
        $page->setFont($font, 10);
        $y = $this->totals($page, $data['pre_refund'], $currency, $y);

        $y -= 8;
        $page->setFont($bold, 11);
        $y = $this->line($page, 'Refund', $y, 14);
        $page->setFont($font, 10);
        $y = $this->totals($page, $data['refund'], $currency, $y, (string) $data['refund_ex_tax']);

        $y -= 8;
        $page->setFont($bold, 11);
        $y = $this->line($page, 'Refund Tax by Rate', $y, 14);
        $page->setFont($font, 10);
        foreach ((array) $data['tax_groups'] as $group) {
            $text = sprintf(
                '%s (code %s): taxable %s  tax %s',
                $this->percent((string) $group['rate']),
                $this->ascii((string) $group['code']),
                $this->money($group['taxable'], $currency),
                $this->money($group['tax'], $currency)
            );
            $y = $this->line($page, $text, $y);
        }

        return $pdf->render();
    }

    /**
     * Refund tax per stored rate and business tax code.
     *
     * The taxable base includes the line's shipping share, as in the calculation (FRD 11).
     *
     * @param RefundFigureLine[] $lines
     * @return array<int, array<string, string>>
     */
    private function taxGroups(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $key = $line->taxCode . '|' . $line->taxRate;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'code' => $line->taxCode,
                    'rate' => $line->taxRate,
                    'taxable' => self::ZERO,
                    'tax' => self::ZERO,
                ];
            }
            $taxable = bcadd($line->rowAmount, $line->shippingAmount, self::SCALE);
            $groups[$key]['taxable'] = bcadd($groups[$key]['taxable'], $taxable, self::SCALE);
            $groups[$key]['tax'] = bcadd($groups[$key]['tax'], $line->taxAmount, self::SCALE);
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * Quantity refunded before this refund, as stored on each item at submission.
     *
     * A receipt regenerated later therefore still shows the same history.
     *
     * @param RefundItemInterface[] $items
     * @return array<int, string> order_item_id => quantity
     */
    private function refundedBefore(array $items): array
    {
        $before = [];
        foreach ($items as $item) {
            $before[(int) $item->getOrderItemId()] = (string) $item->getQtyRefundedBefore();
        }

        return $before;
    }

    /**
     * Print one totals block; the ex-tax row is printed when given.
     *
     * @param \Zend_Pdf_Page $page
     * @param string[] $totals
     * @param string $currency
     * @param float $y
     * @param string|null $exTax
     * @return float
     */
    private function totals(
        \Zend_Pdf_Page $page,
        array $totals,
        string $currency,
        float $y,
        ?string $exTax = null
    ): float {
        $y = $this->line($page, 'Product Subtotal: ' . $this->money($totals['subtotal'], $currency), $y);
        $y = $this->line($page, 'Shipping: ' . $this->money($totals['shipping'], $currency), $y);
        if ($exTax !== null) {
            $y = $this->line($page, 'Ex-tax Amount: ' . $this->money($exTax, $currency), $y);
        }
        $y = $this->line($page, 'Consumption Tax: ' . $this->money($totals['tax'], $currency), $y);
        $y = $this->line($page, 'Total: ' . $this->money($totals['grand_total'], $currency), $y);

        return $y;
    }

    /**
     * Draw one line of text and move down.
     *
     * @param \Zend_Pdf_Page $page
     * @param string $text
     * @param float $y
     * @param int $step
     * @return float
     */
    private function line(\Zend_Pdf_Page $page, string $text, float $y, int $step = 14): float
    {
        $page->drawText($text, 50, $y, 'UTF-8');

        return $y - $step;
    }

    /**
     * Refund date in the store timezone.
     *
     * @param RefundInterface $refund
     * @return string
     */
    private function refundDate(RefundInterface $refund): string
    {
        $createdAt = $refund->getCreatedAt();
        if ($createdAt === null || $createdAt === '') {
            return $this->timezone->date()->format('Y-m-d');
        }

        return $this->timezone->date(new \DateTime($createdAt))->format('Y-m-d');
    }

    /**
     * Amount in whole currency units, for example "JPY 1,200".
     *
     * @param mixed $amount
     * @param string $currency
     * @return string
     */
    private function money(mixed $amount, string $currency): string
    {
        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }

    /**
     * Quantity without trailing decimals, for example "2" for "2.0000".
     *
     * @param string $qty
     * @return string
     */
    private function qty(string $qty): string
    {
        return rtrim(rtrim(sprintf('%.4F', (float) $qty), '0'), '.');
    }

    /**
     * Rate as a percentage, for example "10%" for "0.1000".
     *
     * @param string $rate
     * @return string
     */
    private function percent(string $rate): string
    {
        return $this->qty(bcmul($rate, '100', self::SCALE)) . '%';
    }

    /**
     * Text limited to printable ASCII, which the built-in PDF fonts can draw.
     *
     * @param string $value
     * @return string
     */
    private function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($converted === false) {
            $converted = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
        }

        return (string) preg_replace('/[^\x20-\x7E]/', '?', $converted);
    }
}
