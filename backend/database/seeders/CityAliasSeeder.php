<?php

namespace Database\Seeders;

use App\Models\CityAlias;
use App\Services\Shipping\CityNormalizer;
use Illuminate\Database\Seeder;

/**
 * Seeds the global city-alias table (spec 04 §4.8): Saudi + UAE cities with their common English and
 * Arabic spellings → a canonical key. Idempotent. Orgs extend this per-account with their own rows.
 */
class CityAliasSeeder extends Seeder
{
    /** [country, canonical, en, ar, [alias variants...]] — the canonical/en/ar are added as aliases too. */
    private const CITIES = [
        // Saudi Arabia
        ['SA', 'riyadh', 'Riyadh', 'الرياض', ['riyad', 'ar riyadh', 'ar-riyadh', 'al riyadh']],
        ['SA', 'jeddah', 'Jeddah', 'جدة', ['jiddah', 'jedda', 'jaddah']],
        ['SA', 'makkah', 'Makkah', 'مكة', ['mecca', 'makkah al mukarramah', 'makkah al-mukarramah']],
        ['SA', 'madinah', 'Madinah', 'المدينة المنورة', ['medina', 'al madinah', 'al-madinah', 'madina', 'المدينة']],
        ['SA', 'dammam', 'Dammam', 'الدمام', ['ad dammam', 'ad-dammam']],
        ['SA', 'khobar', 'Khobar', 'الخبر', ['al khobar', 'alkhobar', 'al-khobar']],
        ['SA', 'dhahran', 'Dhahran', 'الظهران', ['az zahran']],
        ['SA', 'taif', 'Taif', 'الطائف', ['al taif', 'at taif', 'at-taif']],
        ['SA', 'buraidah', 'Buraidah', 'بريدة', ['buraydah', 'buraidah']],
        ['SA', 'tabuk', 'Tabuk', 'تبوك', []],
        ['SA', 'abha', 'Abha', 'أبها', []],
        ['SA', 'khamis mushait', 'Khamis Mushait', 'خميس مشيط', ['khamis mushayt']],
        ['SA', 'hail', 'Hail', 'حائل', ['hayil', 'haïl']],
        ['SA', 'jubail', 'Jubail', 'الجبيل', ['al jubail', 'al-jubail']],
        ['SA', 'yanbu', 'Yanbu', 'ينبع', ['yanbu al bahr']],
        ['SA', 'najran', 'Najran', 'نجران', []],
        ['SA', 'qatif', 'Qatif', 'القطيف', ['al qatif']],
        // UAE
        ['AE', 'dubai', 'Dubai', 'دبي', ['dxb']],
        ['AE', 'abu dhabi', 'Abu Dhabi', 'أبوظبي', ['abudhabi', 'abu-dhabi', 'أبو ظبي']],
        ['AE', 'sharjah', 'Sharjah', 'الشارقة', ['al sharjah']],
        ['AE', 'ajman', 'Ajman', 'عجمان', []],
        ['AE', 'ras al khaimah', 'Ras Al Khaimah', 'رأس الخيمة', ['rak', 'ras al-khaimah']],
        ['AE', 'fujairah', 'Fujairah', 'الفجيرة', []],
        ['AE', 'umm al quwain', 'Umm Al Quwain', 'أم القيوين', ['umm al-quwain']],
        ['AE', 'al ain', 'Al Ain', 'العين', ['alain']],
    ];

    public function run(): void
    {
        foreach (self::CITIES as [$country, $canonical, $en, $ar, $variants]) {
            $aliases = array_unique(array_merge([$canonical, $en, $ar], $variants));
            foreach ($aliases as $variant) {
                $key = CityNormalizer::key($variant);
                if ($key === '') {
                    continue;
                }
                CityAlias::updateOrCreate(
                    ['organization_id' => null, 'country_code' => $country, 'alias' => $key],
                    ['canonical' => $canonical, 'canonical_en' => $en, 'canonical_ar' => $ar],
                );
            }
        }
    }
}
