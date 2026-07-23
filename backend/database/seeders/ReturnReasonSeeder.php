<?php

namespace Database\Seeders;

use App\Models\ReturnReason;
use Illuminate\Database\Seeder;

/**
 * The global return reason taxonomy (spec 03 §3.1). organization_id = null → visible to every org.
 * The five logistics codes are the RTO mapping targets carriers report. Idempotent via updateOrCreate.
 */
class ReturnReasonSeeder extends Seeder
{
    public function run(): void
    {
        // [group, code, label_en, label_ar, is_defect, is_customer_fault, default_disposition]
        $reasons = [
            ['customer', 'changed_mind', 'Changed mind', 'غيّر رأيه', false, true, 'restock'],
            ['customer', 'ordered_by_mistake', 'Ordered by mistake', 'طلب بالخطأ', false, true, 'restock'],
            ['customer', 'found_better_price', 'Found a better price', 'وجد سعرًا أفضل', false, true, 'restock'],
            ['customer', 'no_longer_needed', 'No longer needed', 'لم يعد بحاجة إليه', false, true, 'restock'],
            ['customer', 'arrived_late', 'Arrived too late', 'وصل متأخرًا', false, false, 'restock'],
            ['product', 'damaged_in_transit', 'Damaged in transit', 'تالف أثناء الشحن', true, false, 'scrap'],
            ['product', 'defective', 'Defective / not working', 'معيب أو لا يعمل', true, false, 'quarantine'],
            ['product', 'not_as_described', 'Not as described', 'غير مطابق للوصف', true, false, 'restock'],
            ['product', 'wrong_item_sent', 'Wrong item sent', 'تم إرسال منتج خاطئ', true, false, 'restock'],
            ['product', 'wrong_size', 'Wrong size', 'مقاس غير مناسب', false, true, 'restock'],
            ['product', 'wrong_color', 'Wrong colour', 'لون غير مناسب', false, true, 'restock'],
            ['product', 'missing_parts', 'Missing parts', 'أجزاء ناقصة', true, false, 'quarantine'],
            ['product', 'quality_below_expectation', 'Quality below expectation', 'الجودة أقل من المتوقع', false, false, 'restock'],
            ['product', 'expired', 'Expired / near expiry', 'منتهي الصلاحية', true, false, 'scrap'],
            ['logistics', 'delivery_failed', 'Delivery failed', 'فشل التسليم', false, false, 'restock'],
            ['logistics', 'address_incorrect', 'Incorrect address', 'العنوان غير صحيح', false, true, 'restock'],
            ['logistics', 'customer_unreachable', 'Customer unreachable', 'تعذّر الوصول للعميل', false, true, 'restock'],
            ['logistics', 'customer_refused', 'Customer refused parcel', 'العميل رفض استلام الشحنة', false, true, 'restock'],
            ['logistics', 'cod_payment_refused', 'COD payment refused', 'رفض دفع المبلغ عند الاستلام', false, true, 'restock'],
            ['logistics', 'out_of_delivery_zone', 'Outside delivery zone', 'خارج نطاق التوصيل', false, false, 'restock'],
            ['fraud', 'suspected_fraud', 'Suspected fraud', 'اشتباه احتيال', false, true, 'quarantine'],
            ['fraud', 'chargeback', 'Chargeback', 'استرداد قسري من البنك', false, false, 'quarantine'],
            ['other', 'other', 'Other', 'أخرى', false, false, 'restock'],
        ];

        foreach ($reasons as $i => [$group, $code, $en, $ar, $defect, $fault, $disposition]) {
            ReturnReason::updateOrCreate(
                ['organization_id' => null, 'code' => $code],
                [
                    'group' => $group,
                    'label_en' => $en,
                    'label_ar' => $ar,
                    'is_defect' => $defect,
                    'is_customer_fault' => $fault,
                    'default_disposition' => $disposition,
                    'sort_order' => $i,
                ],
            );
        }
    }
}
