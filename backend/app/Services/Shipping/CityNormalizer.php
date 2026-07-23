<?php

namespace App\Services\Shipping;

use App\Models\CityAlias;

/**
 * Map a free-text city to a canonical key (spec 04 §4.8) via the seeded city_aliases table, with a
 * Levenshtein fuzzy fallback for Latin typos. Per-org rows override the global seed.
 */
class CityNormalizer
{
    /** Normalize input the same way aliases are stored: lowercased, Arabic diacritics/tatweel removed. */
    public static function key(string $s): string
    {
        $s = trim(mb_strtolower($s));
        $s = preg_replace('/[\x{0640}\x{064B}-\x{0652}]/u', '', $s); // tatweel + harakat
        $s = preg_replace('/\s+/u', ' ', $s);

        return (string) $s;
    }

    /**
     * @return array{canonical:?string, matched:bool, method:?string, canonical_en:?string, canonical_ar:?string}
     */
    public function normalize(?string $city, string $countryCode = 'SA', ?int $organizationId = null): array
    {
        $miss = ['canonical' => null, 'matched' => false, 'method' => null, 'canonical_en' => null, 'canonical_ar' => null];
        if (! $city || trim($city) === '') {
            return $miss;
        }

        $key = self::key($city);
        $country = strtoupper($countryCode);

        // Exact alias — prefer an org-specific row over the global (null) one.
        $exact = CityAlias::where('country_code', $country)
            ->where('alias', $key)
            ->where(fn ($q) => $q->whereNull('organization_id')->when($organizationId, fn ($w) => $w->orWhere('organization_id', $organizationId)))
            ->orderByRaw('organization_id IS NULL') // non-null (org) first
            ->first();

        if ($exact) {
            return ['canonical' => $exact->canonical, 'matched' => true, 'method' => 'alias', 'canonical_en' => $exact->canonical_en, 'canonical_ar' => $exact->canonical_ar];
        }

        // Fuzzy fallback — Latin only (levenshtein is byte-based; skip multibyte Arabic to avoid noise).
        if (preg_match('/^[\x00-\x7F ]+$/', $key)) {
            $best = null;
            $bestDist = 3; // threshold 2 → accept distance <= 2
            foreach (CityAlias::where('country_code', $country)->whereNull('organization_id')->get() as $alias) {
                if (! preg_match('/^[\x00-\x7F ]+$/', $alias->alias)) {
                    continue;
                }
                $d = levenshtein($key, $alias->alias);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $alias;
                }
            }
            if ($best && $bestDist <= 2) {
                return ['canonical' => $best->canonical, 'matched' => true, 'method' => 'fuzzy', 'canonical_en' => $best->canonical_en, 'canonical_ar' => $best->canonical_ar];
            }
        }

        return $miss;
    }
}
