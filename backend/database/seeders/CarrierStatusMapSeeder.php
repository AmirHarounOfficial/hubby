<?php

namespace Database\Seeders;

use App\Models\CarrierStatusMap;
use Illuminate\Database\Seeder;

/**
 * Seeds the normalized status vocabulary (spec 04 §4.2) for the `manual` carrier, where the merchant
 * types the status directly, so raw == normalized. Real carriers get their own richer maps in their
 * slices. Idempotent via the (carrier_code, raw_code, raw_status) unique key.
 */
class CarrierStatusMapSeeder extends Seeder
{
    /** normalized_status => [is_final, is_exception, en, ar] */
    private const VOCAB = [
        'picked_up' => [false, false, 'Picked up', 'تم الاستلام'],
        'in_transit' => [false, false, 'In transit', 'قيد النقل'],
        'at_origin_hub' => [false, false, 'At origin facility', 'في مركز المصدر'],
        'at_destination_hub' => [false, false, 'At destination facility', 'في مركز الوجهة'],
        'customs_clearance' => [false, false, 'In customs', 'في الجمارك'],
        'out_for_delivery' => [false, false, 'Out for delivery', 'خرج للتوصيل'],
        'delivery_attempted' => [false, true, 'Delivery attempted', 'محاولة توصيل فاشلة'],
        'held' => [false, true, 'Held at facility', 'محتجز في المرفق'],
        'delivered' => [true, false, 'Delivered', 'تم التوصيل'],
        'returned_to_origin' => [false, true, 'Returning to sender', 'قيد الإرجاع للمرسل'],
        'rto_in_transit' => [false, true, 'Return in transit', 'الإرجاع قيد النقل'],
        'rto_delivered' => [true, true, 'Returned to sender', 'تم الإرجاع للمرسل'],
        'lost' => [true, true, 'Lost', 'مفقود'],
        'damaged' => [true, true, 'Damaged', 'تالف'],
        'exception' => [false, true, 'Exception', 'استثناء'],
    ];

    public function run(): void
    {
        foreach (self::VOCAB as $normalized => [$isFinal, $isException, $en, $ar]) {
            CarrierStatusMap::updateOrCreate(
                ['carrier_code' => 'manual', 'raw_code' => $normalized, 'raw_status' => $normalized],
                [
                    'normalized_status' => $normalized,
                    'is_final' => $isFinal,
                    'is_exception' => $isException,
                    'description_en' => $en,
                    'description_ar' => $ar,
                    'priority' => 100,
                ]
            );
        }
    }
}
