<?php

namespace App\Services\Automation;

/**
 * The builder's source of truth (spec 02 §3.5): which fields exist per trigger, which operators are
 * legal, and which actions can be chosen. Served by GET /automation/schema so the frontend never
 * hardcodes the catalogue and can't drift from what OrderSubject actually produces.
 */
class AutomationSchema
{
    public static function describe(): array
    {
        return [
            'triggers' => [
                ['value' => 'order.created', 'label_key' => 'automation.triggers.order_created'],
                ['value' => 'order.updated', 'label_key' => 'automation.triggers.order_updated'],
                ['value' => 'order.status_changed', 'label_key' => 'automation.triggers.order_status_changed'],
            ],
            'fields' => self::orderFields(),
            'operators' => self::operators(),
            // Plain-language operator labels so the builder reads like a sentence, not code.
            'operatorLabels' => self::operatorLabels(),
            'actions' => self::actions(),
        ];
    }

    /**
     * Kept in lockstep with OrderSubject::facts(). Enum fields carry `options` so the builder can
     * offer a dropdown instead of asking a non-technical user to type an exact value.
     */
    private static function orderFields(): array
    {
        $platforms = ['shopify', 'salla', 'zid', 'woocommerce', 'amazon', 'noon', 'trendyol'];
        $payment = ['cod', 'card', 'wallet', 'bank_transfer', 'bnpl', 'marketplace', 'unknown'];
        $countries = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'EG'];
        $currencies = ['SAR', 'AED', 'KWD', 'QAR', 'BHD', 'OMR', 'EGP', 'USD'];

        return [
            ['field' => 'order.channel', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => $platforms],
            ['field' => 'order.status', 'type' => 'string', 'operators' => ['eq', 'neq', 'in', 'not_in', 'contains']],
            ['field' => 'order.total', 'type' => 'decimal', 'operators' => ['gte', 'lte', 'gt', 'lt', 'between', 'eq', 'neq'], 'unit' => 'money'],
            ['field' => 'order.currency', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => $currencies],
            ['field' => 'order.item_count', 'type' => 'int', 'operators' => ['gte', 'lte', 'eq', 'gt', 'lt', 'between']],
            ['field' => 'order.total_quantity', 'type' => 'int', 'operators' => ['gte', 'lte', 'eq', 'gt', 'lt', 'between']],
            ['field' => 'order.skus', 'type' => 'array', 'operators' => ['any_of', 'all_of', 'none_of']],
            ['field' => 'order.product_names', 'type' => 'array', 'operators' => ['any_of', 'all_of', 'none_of']],
            ['field' => 'order.tags', 'type' => 'array', 'operators' => ['any_of', 'none_of', 'all_of', 'is_empty', 'is_not_empty']],
            ['field' => 'order.payment_method', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => $payment],
            ['field' => 'order.is_cod', 'type' => 'bool', 'operators' => ['is_true', 'is_false']],
            ['field' => 'order.shipping_country', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => $countries],
            ['field' => 'order.shipping_city', 'type' => 'string', 'operators' => ['eq', 'neq', 'in', 'contains']],
            ['field' => 'order.customer_email', 'type' => 'string', 'operators' => ['eq', 'contains', 'ends_with', 'is_empty']],
            ['field' => 'order.is_held', 'type' => 'bool', 'operators' => ['is_true', 'is_false']],
            ['field' => 'order.folder', 'type' => 'string', 'operators' => ['eq', 'neq', 'is_empty', 'is_not_empty']],
            ['field' => 'order.created_hour', 'type' => 'int', 'operators' => ['between', 'gte', 'lte', 'eq']],
            ['field' => 'order.age_minutes', 'type' => 'int', 'operators' => ['gte', 'gt', 'lte', 'lt', 'between']],
            ['field' => 'order.previous_status', 'type' => 'string', 'operators' => ['eq', 'neq', 'in']],
        ];
    }

    private static function operators(): array
    {
        return array_keys(self::operatorLabels());
    }

    /** Human phrasing for each operator — the builder shows these, not the codes. */
    private static function operatorLabels(): array
    {
        return [
            'eq' => 'is', 'neq' => 'is not',
            'gt' => 'is more than', 'gte' => 'is at least', 'lt' => 'is less than', 'lte' => 'is at most',
            'between' => 'is between', 'in' => 'is any of', 'not_in' => 'is none of',
            'contains' => 'contains', 'not_contains' => "doesn't contain",
            'starts_with' => 'starts with', 'ends_with' => 'ends with', 'matches' => 'matches pattern',
            'is_empty' => 'is empty', 'is_not_empty' => 'is not empty',
            'any_of' => 'includes any of', 'all_of' => 'includes all of', 'none_of' => 'includes none of',
            'is_true' => 'is yes', 'is_false' => 'is no',
        ];
    }

    /** Actions implemented inline in slice 1; deferred ones are flagged so the UI can mark them. */
    private static function actions(): array
    {
        return [
            ['type' => 'add_tag', 'params' => ['tags'], 'deferred' => false],
            ['type' => 'remove_tag', 'params' => ['tags'], 'deferred' => false],
            ['type' => 'set_status', 'params' => ['status'], 'deferred' => false],
            ['type' => 'assign_folder', 'params' => ['folder'], 'deferred' => false],
            ['type' => 'route_location', 'params' => ['location'], 'deferred' => false],
            ['type' => 'assign_carrier', 'params' => ['carrier', 'service'], 'deferred' => false],
            ['type' => 'hold_order', 'params' => ['reason'], 'deferred' => false],
            ['type' => 'release_hold', 'params' => [], 'deferred' => false],
            ['type' => 'add_note', 'params' => ['text'], 'deferred' => false],
            ['type' => 'stop_processing', 'params' => [], 'deferred' => false],
            ['type' => 'notify', 'params' => ['title', 'message'], 'deferred' => true],
            ['type' => 'call_webhook', 'params' => ['url'], 'deferred' => true],
            ['type' => 'split_order', 'params' => [], 'deferred' => false],
        ];
    }
}
