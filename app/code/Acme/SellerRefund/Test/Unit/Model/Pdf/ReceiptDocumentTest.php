<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Pdf;

use Acme\SellerRefund\Model\Pdf\ReceiptDocument;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercise real PDF page generation, including long products and repeated renderer use.
 */
class ReceiptDocumentTest extends TestCase
{
    /**
     * @return void
     */
    public function testLongReceiptsPaginateAndASecondDocumentStartsFresh(): void
    {
        $document = $this->document(3);
        $short = $document->getPdf([$this->data(1)]);
        self::assertCount(1, $short->pages);
        $long = $document->getPdf([$this->data(40)]);
        self::assertGreaterThan(2, count($long->pages));
        $bytes = $long->render();
        $parsed = \Zend_Pdf::parse($bytes);
        self::assertCount(count($long->pages), $parsed->pages);
        self::assertCount(1, $document->getPdf([$this->data(1)])->pages);
    }

    /**
     * Restore inline translation even if the PDF font or rendering step fails.
     *
     * @return void
     */
    public function testRenderingFailureStillResumesTranslation(): void
    {
        $document = $this->document();
        $document->method('_setFontBold')->willThrowException(new \RuntimeException('Font unavailable.'));
        $this->expectException(\RuntimeException::class);
        $document->getPdf([$this->data(1)]);
    }

    /**
     * Mock only environment and fonts; exercise the real line blocks and page handling.
     *
     * @param int $calls
     * @return ReceiptDocument&\PHPUnit\Framework\MockObject\MockObject
     */
    private function document(int $calls = 1): ReceiptDocument
    {
        $document = $this->getMockBuilder(ReceiptDocument::class)->disableOriginalConstructor()
            ->onlyMethods(['insertLogo', 'insertAddress', '_beforeGetPdf', '_afterGetPdf',
                '_setFontRegular', '_setFontBold'])->getMock();
        $document->expects(self::exactly($calls))->method('_beforeGetPdf');
        $document->expects(self::exactly($calls))->method('_afterGetPdf');
        $document->method('_setFontRegular')->willReturnCallback(
            function ($page, $size) {
                $font = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA);
                $page->setFont($font, $size);

                return $font;
            }
        );
        $document->method('_setFontBold')->willReturnCallback(
            function ($page, $size) {
                $font = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA_BOLD);
                $page->setFont($font, $size);

                return $font;
            }
        );
        $config = new \ReflectionProperty(ReceiptDocument::class, '_scopeConfig');
        $config->setValue($document, $this->createMock(ScopeConfigInterface::class));

        return $document;
    }

    /**
     * Build a long-name fixture without any Magento DB or ERP side effects.
     *
     * @param int $count
     * @return array
     */
    private function data(int $count): array
    {
        $line = [
            'product_name' => str_repeat('Long product name ', 8), 'sku' => str_repeat('W', 64),
            'qty_refund' => '1.0000', 'refunded_before' => '2.0000', 'tax_rate' => '0.1000', 'tax_code' => '010',
        ];
        $money = [
            'unit_price' => 'JPY 100', 'row_amount' => 'JPY 100', 'shipping_amount' => 'JPY 0',
            'tax_amount' => 'JPY 10', 'grand_total' => 'JPY 110',
        ];
        $totals = ['subtotal' => 'JPY 100', 'shipping' => 'JPY 0', 'ex_tax' => 'JPY 100',
            'tax' => 'JPY 10', 'grand_total' => 'JPY 110'];

        return [
            'refund_no' => 'SR-20261113-000001', 'order_no' => 'ORDER-100', 'store_id' => 1,
            'refund_date' => '2026-11-13', 'is_partial' => true, 'currency' => 'JPY',
            'status' => 'cash_refund_pending', 'lines' => array_fill(0, $count, $line), 'tax_groups' => [],
            'display' => ['lines' => array_fill(0, $count, $money), 'pre_refund' => $totals, 'refund' => $totals],
        ];
    }
}
