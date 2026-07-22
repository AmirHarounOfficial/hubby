<?php

namespace Tests\Unit\Profit;

use App\Services\Profit\Money;
use App\Services\Profit\VatCalculator;
use PHPUnit\Framework\TestCase;

/**
 * VAT splitting and exact money arithmetic (spec 01 §3.0, §4.4).
 *
 * Getting the inclusive/exclusive distinction wrong overstates profit by exactly the VAT rate,
 * which in KSA is 15% — large enough to make a losing SKU look profitable.
 */
class VatCalculatorTest extends TestCase
{
    private VatCalculator $vat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vat = new VatCalculator();
    }

    public function test_inclusive_price_is_split_so_net_plus_vat_equals_the_sticker_price(): void
    {
        // A SAR 115.00 VAT-inclusive price at 15% is 100.00 revenue + 15.00 liability.
        [$net, $vatAmount] = $this->vat->split('115.0000', 0.15, inclusive: true);

        $this->assertEquals(100.0, (float) $net);
        $this->assertEquals(15.0, (float) $vatAmount);
        $this->assertEquals(115.0, (float) Money::sum($net, $vatAmount));
    }

    public function test_exclusive_price_adds_vat_on_top(): void
    {
        [$net, $vatAmount] = $this->vat->split('100.0000', 0.15, inclusive: false);

        $this->assertEquals(100.0, (float) $net);
        $this->assertEquals(15.0, (float) $vatAmount);
    }

    public function test_treating_an_inclusive_price_as_exclusive_overstates_revenue_by_the_vat_rate(): void
    {
        // This is the bug the distinction exists to prevent, pinned as a test.
        [$correct] = $this->vat->split('115.0000', 0.15, inclusive: true);
        [$wrong] = $this->vat->split('115.0000', 0.15, inclusive: false);

        $this->assertEquals(100.0, (float) $correct);
        $this->assertEquals(115.0, (float) $wrong);
        $this->assertEqualsWithDelta(0.15, ((float) $wrong / (float) $correct) - 1, 0.0001);
    }

    public function test_zero_rate_and_zero_amount_are_safe(): void
    {
        $this->assertSame(['0.0000', '0.0000'], $this->vat->split('0.0000', 0.15, true));
        $this->assertSame(['50.0000', '0.0000'], $this->vat->split('50.0000', 0.0, true));
    }

    public function test_split_recombines_exactly_even_when_the_division_does_not_land_on_a_cent(): void
    {
        // 100.00 / 1.15 = 86.9565217... — the halves must still sum back to the original.
        [$net, $vatAmount] = $this->vat->split('100.0000', 0.15, inclusive: true);

        $this->assertSame('100.0000', Money::sum($net, $vatAmount));
    }

    public function test_money_arithmetic_is_exact_where_float_would_drift(): void
    {
        // The classic float failure: 0.1 + 0.2 !== 0.3
        $this->assertSame('0.3000', Money::sum('0.1000', '0.2000'));

        // Repeated accumulation stays exact.
        $total = '0.0000';
        for ($i = 0; $i < 1000; $i++) {
            $total = Money::sum($total, '0.0001');
        }
        $this->assertSame('0.1000', $total);
    }

    public function test_money_handles_negative_amounts_and_ratios(): void
    {
        $this->assertSame('-4.0000', Money::multiply('2.0000', -2));
        $this->assertSame('6.0000', Money::subtract('10.0000', '4.0000'));
        $this->assertSame('13.2000', Money::scale('120.0000', 0.11));

        $this->assertEqualsWithDelta(0.25, Money::ratio('25.0000', '100.0000'), 0.0001);
        // Division by zero must be null, not an exception or a silently wrong 0.
        $this->assertNull(Money::ratio('25.0000', '0.0000'));
    }
}
