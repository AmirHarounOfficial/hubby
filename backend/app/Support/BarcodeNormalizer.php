<?php

namespace App\Support;

/**
 * Barcode normalisation (spec 08 §3.3).
 *
 * Warehouse reality drives every rule here: HID scanners emit AIM identifier prefixes, labels get
 * reprinted with stray whitespace, and the same product carries a 12-digit UPC-A in US catalogues
 * and a 13-digit EAN-13 in EU ones. Normalising on both write and read is what makes a scan resolve.
 *
 * Check digits are validated ADVISORY-only: a failing digit is reported so it can be logged, but it
 * never blocks resolution — hand-typed and reprinted barcodes are frequently wrong, and refusing to
 * pick an item the operator is physically holding is worse than accepting a bad check digit.
 */
class BarcodeNormalizer
{
    /** Trim, strip control/zero-width chars, drop AIM prefix, collapse whitespace, uppercase. */
    public static function normalize(string $raw): string
    {
        $s = $raw;

        // Zero-width and BOM characters — invisible, and they break equality comparisons.
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        // Control characters (scanners often append CR/LF as a "submit" key).
        $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? $s;
        $s = trim($s);

        // AIM identifier prefix, e.g. ]E0 (EAN-13), ]C1 (Code128) — emitted by some HID scanners.
        $s = preg_replace('/^\](?:[A-Za-z][0-9])/', '', $s) ?? $s;

        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);

        return mb_strtoupper($s);
    }

    /**
     * Every equivalent form of a barcode, most-canonical first. A 12-digit UPC-A also yields its
     * 13-digit EAN-13 form (and vice-versa when the EAN is a zero-padded UPC), and a UPC-E yields
     * its expanded form — so one scan matches a catalogue stored in either convention.
     *
     * @return array<int, string>
     */
    public static function variants(string $raw): array
    {
        $base = self::normalize($raw);
        $out = [$base];

        if (ctype_digit($base)) {
            if (strlen($base) === 12) {
                $out[] = '0'.$base;                       // UPC-A → EAN-13
            } elseif (strlen($base) === 13 && str_starts_with($base, '0')) {
                $out[] = substr($base, 1);                // EAN-13 → UPC-A
            } elseif (strlen($base) === 8) {
                if ($expanded = self::expandUpcE($base)) {
                    $out[] = $expanded;                   // UPC-E → UPC-A
                    $out[] = '0'.$expanded;               // and its EAN-13 form
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Expand an 8-digit UPC-E to its 12-digit UPC-A form, or null if it isn't a UPC-E. */
    public static function expandUpcE(string $upce): ?string
    {
        if (! ctype_digit($upce) || strlen($upce) !== 8) {
            return null;
        }

        $system = $upce[0];
        if ($system !== '0' && $system !== '1') {
            return null; // only number systems 0 and 1 have a UPC-E form
        }

        $d = substr($upce, 1, 6);   // the six data digits
        $check = $upce[7];
        $last = $d[5];

        $body = match (true) {
            in_array($last, ['0', '1', '2'], true) => substr($d, 0, 2).$last.'0000'.substr($d, 2, 3),
            $last === '3' => substr($d, 0, 3).'00000'.substr($d, 3, 2),
            $last === '4' => substr($d, 0, 4).'00000'.$d[4],
            default => substr($d, 0, 5).'0000'.$last,
        };

        return $system.$body.$check;
    }

    /**
     * Is the trailing check digit correct for an EAN-13/UPC-A/EAN-8 payload?
     * Returns null when the payload isn't a fixed-length numeric symbology we can check.
     */
    public static function checkDigitValid(string $raw): ?bool
    {
        $s = self::normalize($raw);
        if (! ctype_digit($s) || ! in_array(strlen($s), [8, 12, 13], true)) {
            return null;
        }

        // UPC-E carries its own check digit over the expanded form.
        if (strlen($s) === 8 && ($expanded = self::expandUpcE($s))) {
            $s = $expanded;
        }

        $padded = str_pad($s, 13, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $padded[$i]) * ($i % 2 === 0 ? 1 : 3);
        }

        return ((10 - $sum % 10) % 10) === (int) $padded[12];
    }
}
