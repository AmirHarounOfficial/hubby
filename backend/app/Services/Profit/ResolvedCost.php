<?php

namespace App\Services\Profit;

/**
 * The outcome of resolving a landed unit cost (spec 01 §4.2).
 *
 * `missing()` is a deliberate first-class outcome. We never invent a COGS figure: a zero cost
 * produces an obviously-too-good margin a merchant will question, whereas a guessed cost
 * produces a plausible wrong number they will trust. Sellerboard's accuracy complaints come
 * from the second failure mode.
 */
class ResolvedCost
{
    private function __construct(
        public readonly string $landedUnitCostBase,
        public readonly ?string $method,
        public readonly ?string $currency,
        public readonly bool $isMissing,
        public readonly bool $isEstimated,
        public readonly ?int $sourceId,
    ) {
    }

    public static function found(
        string $landedUnitCostBase,
        string $method,
        string $currency,
        int $sourceId,
        bool $isEstimated = false,
    ): self {
        return new self($landedUnitCostBase, $method, $currency, false, $isEstimated, $sourceId);
    }

    public static function missing(): self
    {
        return new self('0.0000', null, null, true, true, null);
    }

    public function isFound(): bool
    {
        return ! $this->isMissing;
    }

    /** Total base-currency cost for a quantity, as an exact 4 dp string. */
    public function totalFor(int $quantity): string
    {
        $scaled = (int) round(((float) $this->landedUnitCostBase) * 10000) * $quantity;
        $sign = $scaled < 0 ? '-' : '';
        $abs = abs($scaled);

        return sprintf('%s%d.%04d', $sign, intdiv($abs, 10000), $abs % 10000);
    }
}
