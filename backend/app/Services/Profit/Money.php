<?php

namespace App\Services\Profit;

/**
 * Exact fixed-point money arithmetic at 4 dp (spec 01 §3.0).
 *
 * Everything is done in integer minor units and only rendered back to a decimal string at the
 * edge, so no intermediate value ever touches float accumulation. A decimal(15,4) value scales
 * to at most ~1e15, comfortably inside a 64-bit int.
 *
 * Deliberately avoids ext-bcmath: it is present in the dev image and CI but NOT in
 * docker/Dockerfile.prod, so a bcmath-based implementation would pass every gate and then fatal
 * in production only.
 */
final class Money
{
    public const SCALE = 10000; // 4 dp

    /** Decimal string/number → integer minor units. */
    public static function toMinor(string|float|int|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * self::SCALE);
    }

    /** Integer minor units → fixed 4 dp decimal string. */
    public static function fromMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return sprintf('%s%d.%04d', $sign, intdiv($abs, self::SCALE), $abs % self::SCALE);
    }

    /** Sum any number of decimal amounts exactly. */
    public static function sum(string|float|int|null ...$amounts): string
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += self::toMinor($amount);
        }

        return self::fromMinor($total);
    }

    /** amount × integer quantity. */
    public static function multiply(string|float|int|null $amount, int $quantity): string
    {
        return self::fromMinor(self::toMinor($amount) * $quantity);
    }

    /** amount × arbitrary factor (FX rate, allocation weight), rounded half-up at 4 dp. */
    public static function scale(string|float|int|null $amount, string|float|int $factor): string
    {
        return self::fromMinor((int) round(self::toMinor($amount) * (float) $factor));
    }

    public static function subtract(string|float|int|null $a, string|float|int|null $b): string
    {
        return self::fromMinor(self::toMinor($a) - self::toMinor($b));
    }

    /** Ratio of two amounts as a float, or null when the denominator is zero. */
    public static function ratio(string|float|int|null $numerator, string|float|int|null $denominator): ?float
    {
        $d = self::toMinor($denominator);

        return $d === 0 ? null : self::toMinor($numerator) / $d;
    }

    public static function isZero(string|float|int|null $amount): bool
    {
        return self::toMinor($amount) === 0;
    }
}
