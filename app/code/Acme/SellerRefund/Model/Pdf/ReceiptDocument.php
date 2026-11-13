<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Pdf;

use Magento\Sales\Model\Order\Pdf\AbstractPdf;
use Magento\Store\Model\ScopeInterface;

/**
 * Magento sales PDF layout for the immutable seller refund snapshot.
 */
class ReceiptDocument extends AbstractPdf
{
    private const BOTTOM = 65;
    private const LEFT = 35;
    private const RIGHT = 560;

    /**
     * Current document identity, repeated on continuation pages.
     *
     * @var array<string, mixed>
     */
    private array $receipt = [];

    /**
     * Render prepared data using Magento fonts, line blocks and page management.
     *
     * @param array $documents
     * @return \Zend_Pdf
     */
    public function getPdf($documents = [])
    {
        $this->_beforeGetPdf();
        try {
            $this->_setPdf(new \Zend_Pdf());
            foreach ($documents as $data) {
                $this->receipt = $data;
                $page = $this->newPage(['first_page' => true]);
                $page = $this->drawTotals($page, (string) __('Pre-refund Total (Seller Items)'), 'pre_refund');
                $page = $this->ensureSpace($page, 70);
                $this->drawHeading($page, (string) __('Refunded Items'));
                $this->_drawHeader($page);
                foreach ($data['lines'] as $index => $line) {
                    $page = $this->drawItem($page, $line, $data['display']['lines'][$index]);
                }
                $this->y -= 15;
                $page = $this->drawTotals($page, (string) __('Refund Breakdown'), 'refund');
                $this->drawTaxGroups($page);
            }
            $this->drawFooters();

            return $this->_getPdf();
        } finally {
            $this->_afterGetPdf();
        }
    }

    /**
     * Add identity and optional repeated item headings to every new page.
     *
     * @param array $settings
     * @return \Zend_Pdf_Page
     */
    public function newPage(array $settings = [])
    {
        $page = parent::newPage($settings);
        if (!empty($settings['first_page'])) {
            $this->insertLogo($page, $this->receipt['store_id']);
            $this->insertAddress($page, $this->receipt['store_id']);
            $this->y = min(770, $this->y - 25);
        }
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->_setFontBold($page, 17);
        $page->drawText((string) __('Seller Refund Receipt'), self::LEFT, $this->y, 'UTF-8');
        $this->y -= 22;
        $page = $this->drawTextRows($page, [
            (string) __('Refund # %1', $this->receipt['refund_no']),
            (string) __('Order # %1', $this->receipt['order_no']),
        ]);
        if (!empty($settings['first_page'])) {
            $page = $this->drawTextRows($page, [
                (string) __('Refund date: %1', $this->receipt['refund_date']),
                (string) ($this->receipt['is_partial'] ? __('Partial Refund') : __('Full Refund'))
                    . ' | ' . (string) __('Currency: %1', $this->receipt['currency']),
                (string) __('Status: %1', $this->receipt['status_label'] ?? $this->receipt['status']),
            ]);
            $registration = $this->_scopeConfig->getValue(
                'general/store_information/merchant_vat_number',
                ScopeInterface::SCOPE_STORE,
                $this->receipt['store_id']
            );
            if ($registration) {
                $page = $this->drawTextRows($page, [(string) __('Tax registration: %1', $registration)]);
            }
        }
        $this->y -= 15;
        if (!empty($settings['table_header'])) {
            $this->_drawHeader($page);
        }

        return $page;
    }

    /**
     * Draw the item header again after every item-table page break.
     *
     * @param \Zend_Pdf_Page $page
     * @return void
     */
    protected function _drawHeader(\Zend_Pdf_Page $page)
    {
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0.93));
        $page->drawRectangle(25, $this->y + 5, 570, $this->y - 17);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->y -= 9;
        $columns = [];
        foreach ([
            [(string) __('Product / SKU'), self::LEFT, 'left'],
            [(string) __('Qty'), 265, 'right'],
            [(string) __('Ex-tax'), 340, 'right'],
            [(string) __('Shipping'), 410, 'right'],
            [(string) __('Tax'), 475, 'right'],
            [(string) __('Total'), self::RIGHT, 'right'],
        ] as [$text, $feed, $align]) {
            $columns[] = ['text' => $text, 'feed' => $feed, 'align' => $align, 'font' => 'bold', 'font_size' => 9];
        }
        $this->drawLineBlocks($page, [['lines' => [$columns], 'height' => 12]]);
        $this->y -= 12;
    }

    /**
     * Wrap product/SKU text to actual font width and keep each item above the footer.
     *
     * @param \Zend_Pdf_Page $page
     * @param array $line
     * @param array $display
     * @return \Zend_Pdf_Page
     */
    private function drawItem(\Zend_Pdf_Page $page, array $line, array $display): \Zend_Pdf_Page
    {
        $description = array_merge(
            $this->wrap($page, $line['product_name'], 190),
            $this->wrap($page, (string) __('SKU: %1', $line['sku']), 190),
            $this->wrap($page, (string) __('Unit price: %1', $display['unit_price']), 190),
            $this->wrap(
                $page,
                (string) __('Tax: %1% (code %2)', $this->percent($line['tax_rate']), $line['tax_code']),
                190
            ),
            $this->wrap($page, (string) __('Previously refunded: %1', $this->quantity($line['refunded_before'])), 190)
        );
        $page = $this->ensureSpace($page, count($description) * 11 + 15, true);
        $columns = [['text' => $description, 'feed' => self::LEFT, 'font_size' => 9]];
        foreach ([
            [$this->quantity($line['qty_refund']), 265],
            [$display['row_amount'], 340],
            [$display['shipping_amount'], 410],
            [$display['tax_amount'], 475],
            [$display['grand_total'], self::RIGHT],
        ] as [$text, $feed]) {
            $columns[] = [
                'text' => $this->wrap($page, $text, 65, 8), 'feed' => $feed,
                'align' => 'right', 'font_size' => 8,
            ];
        }
        $page = $this->drawLineBlocks($page, [['lines' => [$columns], 'height' => 11]], ['table_header' => true]);
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.8));
        $page->setLineWidth(0.4);
        $page->drawLine(self::LEFT, $this->y + 1, self::RIGHT, $this->y + 1);
        $this->y -= 13;

        return $page;
    }

    /**
     * Draw original and refund totals as distinct, indivisible sections.
     *
     * @param \Zend_Pdf_Page $page
     * @param string $title
     * @param string $key
     * @return \Zend_Pdf_Page
     */
    private function drawTotals(\Zend_Pdf_Page $page, string $title, string $key): \Zend_Pdf_Page
    {
        $page = $this->ensureSpace($page, 125);
        $this->drawHeading($page, $title);
        foreach ([
            'subtotal' => __('Product Subtotal'), 'shipping' => __('Shipping'),
            'ex_tax' => __('Ex-tax Amount'), 'tax' => __('Consumption Tax'), 'grand_total' => __('Grand Total'),
        ] as $field => $label) {
            $bold = $field === 'grand_total' ? 'bold' : 'regular';
            $page = $this->drawLineBlocks($page, [['lines' => [[
                ['text' => (string) $label, 'feed' => self::LEFT, 'font' => $bold, 'font_size' => 10],
                ['text' => $this->receipt['display'][$key][$field], 'feed' => self::RIGHT,
                    'align' => 'right', 'font' => $bold, 'font_size' => 10],
            ]], 'height' => 16]]);
        }
        $this->y -= 15;

        return $page;
    }

    /**
     * Print tax-exclusive bases and stored tax amounts by business code and rate.
     *
     * @param \Zend_Pdf_Page $page
     * @return void
     */
    private function drawTaxGroups(\Zend_Pdf_Page $page): void
    {
        $page = $this->ensureSpace($page, 65);
        $this->drawHeading($page, (string) __('Refund Tax by Rate'));
        foreach ($this->receipt['tax_groups'] as $index => $group) {
            if ($this->y < self::BOTTOM + 30) {
                $page = $this->newPage();
                $this->drawHeading($page, (string) __('Refund Tax by Rate'));
            }
            $display = $this->receipt['display']['tax_groups'][$index];
            $page = $this->drawTextRows($page, [
                (string) __('%1% (code %2)', $this->percent($group['rate']), $group['code']),
                (string) __('Ex-tax: %1 | Tax: %2', $display['taxable'], $display['tax']),
            ]);
            $this->y -= 5;
        }
    }

    /**
     * Draw a section heading.
     *
     * @param \Zend_Pdf_Page $page
     * @param string $title
     * @return void
     */
    private function drawHeading(\Zend_Pdf_Page $page, string $title): void
    {
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->_setFontBold($page, 11);
        $page->drawText($title, self::LEFT, $this->y, 'UTF-8');
        $this->y -= 20;
    }

    /**
     * Reserve space without allowing receipt text to collide with page footers.
     *
     * @param \Zend_Pdf_Page $page
     * @param int $height
     * @param bool $items
     * @return \Zend_Pdf_Page
     */
    private function ensureSpace(\Zend_Pdf_Page $page, int $height, bool $items = false): \Zend_Pdf_Page
    {
        return $this->y - $height < self::BOTTOM ? $this->newPage(['table_header' => $items]) : $page;
    }

    /**
     * Draw wrapped metadata using Magento's line-block API.
     *
     * @param \Zend_Pdf_Page $page
     * @param string[] $rows
     * @return \Zend_Pdf_Page
     */
    private function drawTextRows(\Zend_Pdf_Page $page, array $rows): \Zend_Pdf_Page
    {
        foreach ($rows as $text) {
            $page = $this->drawLineBlocks($page, [['lines' => [[
                ['text' => $this->wrap($page, $text, 515), 'feed' => self::LEFT, 'font_size' => 9],
            ]], 'height' => 12]]);
        }

        return $page;
    }

    /**
     * Split even unbroken SKUs by measured font width, retaining UTF-8 text.
     *
     * @param \Zend_Pdf_Page $page
     * @param string $text
     * @param int $width
     * @param int $size
     * @return string[]
     */
    private function wrap(\Zend_Pdf_Page $page, string $text, int $width, int $size = 9): array
    {
        $font = $this->_setFontRegular($page, $size);
        $lines = [];
        $line = '';
        foreach (mb_str_split(trim(strip_tags($text))) as $character) {
            if ($line !== '' && $this->widthForStringUsingFontSize($line . $character, $font, $size) > $width) {
                $lines[] = rtrim($line);
                $line = '';
            }
            $line .= $character;
        }
        $lines[] = trim($line);

        return $lines;
    }

    /**
     * Format a snapshot quantity without introducing floating-point arithmetic.
     *
     * @param string $value
     * @return string
     */
    private function quantity(string $value): string
    {
        return str_contains($value, '.') ? (rtrim(rtrim($value, '0'), '.') ?: '0') : $value;
    }

    /**
     * Convert the stored fractional rate to a human-readable percentage.
     *
     * @param string $rate
     * @return string
     */
    private function percent(string $rate): string
    {
        return $this->quantity(bcmul($rate, '100', 4));
    }

    /**
     * Add page numbers without implying that a pending refund has been paid.
     *
     * @return void
     */
    private function drawFooters(): void
    {
        $pages = $this->_getPdf()->pages;
        foreach ($pages as $index => $page) {
            $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0.4));
            $this->_setFontRegular($page, 8);
            $page->drawText((string) __('Seller refund adjustment - see status above.'), self::LEFT, 35, 'UTF-8');
            $page->drawText((string) __('Page %1 of %2', $index + 1, count($pages)), 480, 35, 'UTF-8');
        }
    }
}
