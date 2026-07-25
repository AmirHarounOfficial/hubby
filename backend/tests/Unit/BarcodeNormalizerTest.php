<?php

namespace Tests\Unit;

use App\Support\BarcodeNormalizer;
use PHPUnit\Framework\TestCase;

/** Barcode normalisation (spec 08 §3.3) — the warehouse-reality rules. */
class BarcodeNormalizerTest extends TestCase
{
    public function test_it_strips_scanner_noise(): void
    {
        // HID scanners append CR/LF and sometimes an AIM identifier prefix.
        $this->assertSame('4006381333931', BarcodeNormalizer::normalize("]E04006381333931\r\n"));
        $this->assertSame('ABC-123', BarcodeNormalizer::normalize("  abc-123 \t"));
        $this->assertSame('4006381333931', BarcodeNormalizer::normalize("\u{200B}4006381333931\u{FEFF}"));
    }

    public function test_upc_a_and_ean13_are_treated_as_the_same_item(): void
    {
        // US catalogues carry the 12-digit UPC-A, EU catalogues the 13-digit EAN-13.
        $this->assertContains('0036000291452', BarcodeNormalizer::variants('036000291452'));
        $this->assertContains('036000291452', BarcodeNormalizer::variants('0036000291452'));
    }

    public function test_upc_e_expands_to_upc_a(): void
    {
        // 04252614 → 042100005264 is the canonical worked example of UPC-E expansion.
        $this->assertSame('042100005264', BarcodeNormalizer::expandUpcE('04252614'));
        $this->assertContains('042100005264', BarcodeNormalizer::variants('04252614'));
    }

    public function test_check_digits_are_validated_but_advisory(): void
    {
        $this->assertTrue(BarcodeNormalizer::checkDigitValid('4006381333931'));
        $this->assertFalse(BarcodeNormalizer::checkDigitValid('4006381333930'));
        // Non-fixed-length symbologies simply aren't checkable.
        $this->assertNull(BarcodeNormalizer::checkDigitValid('ABC-123'));
    }
}
