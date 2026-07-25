<?php

namespace App\Services\Invoicing;

/**
 * Invoice VAT arithmetic (spec 05 §5.7).
 *
 * Two rules do most of the work here, and both are places where naive implementations get rejected:
 *
 *  1. Document totals are computed AT DOCUMENT LEVEL, not as a sum of rounded line values. ZATCA is
 *     explicit: VAT is "rounded on document level and not as a summation of rounded Invoice line VAT
 *     amounts." So VAT is grouped by (category, percent), each group rounded once, then summed.
 *  2. Derive, never round twice. From a VAT-inclusive price we compute net, then take VAT as
 *     gross − net, so the pair always reconstitutes the original gross exactly.
 *
 * All money is handled as scaled integers (halalas/cents), never floats — float drift on 15-digit
 * decimals is a real rejection cause.
 */
class TaxCalculator
{
    /** Rounding drift beyond this (in currency units) refuses issuance rather than risking rejection. */
    public const DRIFT_TOLERANCE_MINOR = 2; // 0.02

    /**
     * Split a VAT-inclusive gross amount into net + VAT.
     *
     * @return array{net:string, vat:string}
     */
    public function splitInclusive(string|float|int $gross, float $ratePercent): array
    {
        $grossMinor = $this->toMinor($gross);
        // Round-half-up on the net, then derive VAT so net + vat === gross exactly.
        $netMinor = (int) $this->roundHalfUp($grossMinor / (1 + $ratePercent / 100));

        return [
            'net' => $this->fromMinor($netMinor),
            'vat' => $this->fromMinor($grossMinor - $netMinor),
        ];
    }

    /**
     * VAT for a tax-exclusive net amount.
     *
     * @return array{net:string, vat:string}
     */
    public function splitExclusive(string|float|int $net, float $ratePercent): array
    {
        $netMinor = $this->toMinor($net);
        $vatMinor = (int) $this->roundHalfUp($netMinor * $ratePercent / 100);

        return ['net' => $this->fromMinor($netMinor), 'vat' => $this->fromMinor($vatMinor)];
    }

    /**
     * Document totals from prepared lines (§5.7). Each line is
     * ['line_extension_amount' => net, 'tax_category' => 'S', 'tax_percent' => 15.0,
     *  'line_amount_with_tax' => gross].
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array{line_extension_amount:string, tax_exclusive_amount:string, tax_amount:string,
     *               tax_inclusive_amount:string, payable_amount:string,
     *               subtotals:array<int, array{tax_category:string, tax_percent:float, taxable_amount:string, tax_amount:string}>,
     *               drift_minor:int}
     */
    public function documentTotals(
        array $lines,
        string|float|int $allowanceTotal = 0,
        string|float|int $chargeTotal = 0,
        string|float|int $prepaid = 0,
    ): array {
        $lineExtensionMinor = 0;
        $groups = [];   // "category|percent" => taxable minor

        foreach ($lines as $line) {
            $netMinor = $this->toMinor($line['line_extension_amount']);
            $lineExtensionMinor += $netMinor;

            $key = ($line['tax_category'] ?? 'S').'|'.number_format((float) ($line['tax_percent'] ?? 0), 2, '.', '');
            $groups[$key] = ($groups[$key] ?? 0) + $netMinor;
        }

        $allowanceMinor = $this->toMinor($allowanceTotal);
        $chargeMinor = $this->toMinor($chargeTotal);
        $taxExclusiveMinor = $lineExtensionMinor - $allowanceMinor + $chargeMinor;

        // Round each (category, percent) group once, then sum — BT-110.
        $subtotals = [];
        $taxMinor = 0;
        foreach ($groups as $key => $taxableMinor) {
            [$category, $percent] = explode('|', $key);
            $percent = (float) $percent;

            // Spread a document-level allowance/charge across groups by share of net, so the taxable
            // base of each group reflects the document total rather than the raw line sum.
            $share = $lineExtensionMinor !== 0 ? $taxableMinor / $lineExtensionMinor : 0;
            $groupTaxableMinor = (int) $this->roundHalfUp($taxExclusiveMinor * $share);

            $groupTaxMinor = (int) $this->roundHalfUp($groupTaxableMinor * $percent / 100);
            $taxMinor += $groupTaxMinor;

            $subtotals[] = [
                'tax_category' => $category,
                'tax_percent' => $percent,
                'taxable_amount' => $this->fromMinor($groupTaxableMinor),
                'tax_amount' => $this->fromMinor($groupTaxMinor),
            ];
        }

        $taxInclusiveMinor = $taxExclusiveMinor + $taxMinor;
        $payableMinor = $taxInclusiveMinor - $this->toMinor($prepaid);

        // Drift guard: the sum of line gross amounts must reconcile with the document total.
        $lineGrossMinor = 0;
        foreach ($lines as $line) {
            $lineGrossMinor += $this->toMinor($line['line_amount_with_tax'] ?? 0);
        }
        $drift = abs($lineGrossMinor - ($lineExtensionMinor + $taxMinor));

        return [
            'line_extension_amount' => $this->fromMinor($lineExtensionMinor),
            'tax_exclusive_amount' => $this->fromMinor($taxExclusiveMinor),
            'tax_amount' => $this->fromMinor($taxMinor),
            'tax_inclusive_amount' => $this->fromMinor($taxInclusiveMinor),
            'payable_amount' => $this->fromMinor($payableMinor),
            'subtotals' => $subtotals,
            'drift_minor' => $drift,
        ];
    }

    public function exceedsDriftTolerance(int $driftMinor): bool
    {
        return $driftMinor > self::DRIFT_TOLERANCE_MINOR;
    }

    private function toMinor(string|float|int|null $amount): int
    {
        return (int) $this->roundHalfUp(((float) ($amount ?? 0)) * 100);
    }

    private function fromMinor(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /** Half-up regardless of sign (PHP's round() is half-away-from-zero, which differs on negatives). */
    private function roundHalfUp(float $value): float
    {
        return floor($value + 0.5);
    }
}
