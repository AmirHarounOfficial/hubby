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
            'actions' => self::actions(),
        ];
    }

    /** Kept in lockstep with OrderSubject::facts(). */
    private static function orderFields(): array
    {
        return [
            ['field' => 'order.channel', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['field' => 'order.status', 'type' => 'string', 'operators' => ['eq', 'neq', 'in', 'not_in', 'contains']],
            ['field' => 'order.total', 'type' => 'decimal', 'operators' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between']],
            ['field' => 'order.currency', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['field' => 'order.item_count', 'type' => 'int', 'operators' => ['eq', 'gt', 'gte', 'lt', 'lte', 'between']],
            ['field' => 'order.total_quantity', 'type' => 'int', 'operators' => ['eq', 'gt', 'gte', 'lt', 'lte', 'between']],
            ['field' => 'order.skus', 'type' => 'array', 'operators' => ['any_of', 'all_of', 'none_of']],
            ['field' => 'order.product_names', 'type' => 'array', 'operators' => ['any_of', 'all_of', 'none_of']],
            ['field' => 'order.tags', 'type' => 'array', 'operators' => ['any_of', 'all_of', 'none_of', 'is_empty', 'is_not_empty']],
            ['field' => 'order.payment_method', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['field' => 'order.is_cod', 'type' => 'bool', 'operators' => ['is_true', 'is_false', 'eq']],
            ['field' => 'order.shipping_country', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in']],
            ['field' => 'order.shipping_city', 'type' => 'string', 'operators' => ['eq', 'neq', 'in', 'contains']],
            ['field' => 'order.customer_email', 'type' => 'string', 'operators' => ['eq', 'contains', 'ends_with', 'is_empty']],
            ['field' => 'order.is_held', 'type' => 'bool', 'operators' => ['is_true', 'is_false']],
            ['field' => 'order.folder', 'type' => 'string', 'operators' => ['eq', 'neq', 'is_empty', 'is_not_empty']],
            ['field' => 'order.created_hour', 'type' => 'int', 'operators' => ['eq', 'between', 'gte', 'lte']],
            ['field' => 'order.age_minutes', 'type' => 'int', 'operators' => ['gt', 'gte', 'lt', 'lte', 'between']],
        ];
    }

    private static function operators(): array
    {
        return [
            'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'not_in',
            'contains', 'not_contains', 'starts_with', 'ends_with', 'matches',
            'is_empty', 'is_not_empty', 'any_of', 'all_of', 'none_of', 'is_true', 'is_false',
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
            ['type' => 'notify', 'params' => ['channel', 'template'], 'deferred' => true],
            ['type' => 'call_webhook', 'params' => ['url'], 'deferred' => true],
            ['type' => 'split_order', 'params' => [], 'deferred' => true],
        ];
    }
}
