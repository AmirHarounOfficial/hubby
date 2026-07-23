<?php

namespace App\Services\Automation;

/**
 * Ready-made rule recipes (spec 02 — the ease-of-use differentiator). A merchant starts from one of
 * these and changes a value or two, instead of facing a blank builder and learning what an
 * "operator" is. Each template is a complete, valid rule the builder can load and edit.
 *
 * Kept as plain data (no table) so it ships in the app and stays in lockstep with the schema.
 */
class AutomationTemplates
{
    public static function all(): array
    {
        return [
            [
                'id' => 'hold_high_value_cod',
                'category' => 'risk',
                'icon' => 'shield-alert',
                'name' => 'Hold high-value COD orders for review',
                'description' => 'Cash-on-delivery orders above a threshold are parked for a human to confirm before they ship.',
                'rule' => [
                    'name' => 'Hold high-value COD orders',
                    'trigger' => 'order.created',
                    'run_mode' => 'live',
                    'conditions' => ['match' => 'all', 'rules' => [
                        ['field' => 'order.is_cod', 'operator' => 'is_true'],
                        ['field' => 'order.total', 'operator' => 'gte', 'value' => 1000],
                    ]],
                    'actions' => [
                        ['id' => 'a1', 'type' => 'hold_order', 'reason' => 'High-value COD — needs confirmation'],
                        ['id' => 'a2', 'type' => 'add_tag', 'tags' => ['cod-review']],
                    ],
                ],
            ],
            [
                'id' => 'flag_big_baskets',
                'category' => 'fulfilment',
                'icon' => 'package',
                'name' => 'Flag large orders for careful packing',
                'description' => 'Orders with many items get a tag so the warehouse knows to double-check the pack.',
                'rule' => [
                    'name' => 'Flag large orders',
                    'trigger' => 'order.created',
                    'run_mode' => 'live',
                    'conditions' => ['match' => 'all', 'rules' => [
                        ['field' => 'order.total_quantity', 'operator' => 'gte', 'value' => 5],
                    ]],
                    'actions' => [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['bulk-pack']]],
                ],
            ],
            [
                'id' => 'route_city_folder',
                'category' => 'organisation',
                'icon' => 'map-pin',
                'name' => 'Group orders by city',
                'description' => 'Send orders shipping to a specific city into their own queue for a local courier.',
                'rule' => [
                    'name' => 'Route Riyadh orders',
                    'trigger' => 'order.created',
                    'run_mode' => 'live',
                    'conditions' => ['match' => 'all', 'rules' => [
                        ['field' => 'order.shipping_city', 'operator' => 'eq', 'value' => 'Riyadh'],
                    ]],
                    'actions' => [['id' => 'a1', 'type' => 'assign_folder', 'folder' => 'Riyadh — local courier']],
                ],
            ],
            [
                'id' => 'hold_missing_address',
                'category' => 'risk',
                'icon' => 'map-pin-off',
                'name' => 'Catch orders with a missing country',
                'description' => 'If the shipping country is blank, hold the order so no one ships it blind.',
                'rule' => [
                    'name' => 'Hold orders missing a country',
                    'trigger' => 'order.created',
                    'run_mode' => 'live',
                    'conditions' => ['match' => 'all', 'rules' => [
                        ['field' => 'order.shipping_country', 'operator' => 'is_empty'],
                    ]],
                    'actions' => [
                        ['id' => 'a1', 'type' => 'hold_order', 'reason' => 'No shipping country'],
                        ['id' => 'a2', 'type' => 'add_note', 'text' => 'Auto-held: shipping country was empty on {{ order.external_id }}.'],
                    ],
                ],
            ],
            [
                'id' => 'tag_fragile',
                'category' => 'fulfilment',
                'icon' => 'wine',
                'name' => 'Handle fragile products with care',
                'description' => 'Orders containing fragile SKUs are tagged so they get bubble wrap and the right courier.',
                'rule' => [
                    'name' => 'Tag fragile orders',
                    'trigger' => 'order.created',
                    'run_mode' => 'live',
                    'conditions' => ['match' => 'all', 'rules' => [
                        ['field' => 'order.skus', 'operator' => 'any_of', 'value' => ['FRAGILE-*']],
                    ]],
                    'actions' => [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['fragile']]],
                ],
            ],
            [
                'id' => 'notify_big_order',
                'category' => 'alerts',
                'icon' => 'bell-ring',
                'name' => 'Get notified about big orders',
                'description' => 'A dashboard notification the moment an order over a threshold comes in.',
                'rule' => [
                    'name' => 'Notify me about big orders',
                    'trigger' => 'order.created',
                    'run_mode' => 'live',
                    'conditions' => ['match' => 'all', 'rules' => [
                        ['field' => 'order.total', 'operator' => 'gte', 'value' => 2000],
                    ]],
                    'actions' => [[
                        'id' => 'a1', 'type' => 'notify', 'channels' => ['in_app'], 'level' => 'success',
                        'title' => 'Big order just came in',
                        'message' => 'Order {{ order.external_id }} for {{ order.total }} {{ order.currency }}.',
                    ]],
                ],
            ],
            [
                'id' => 'tag_new_channel',
                'category' => 'organisation',
                'icon' => 'store',
                'name' => 'Label orders by sales channel',
                'description' => 'Automatically tag every order with the channel it came from, for easy filtering.',
                'rule' => [
                    'name' => 'Tag marketplace orders',
                    'trigger' => 'order.created',
                    'run_mode' => 'live',
                    'conditions' => ['match' => 'all', 'rules' => [
                        ['field' => 'order.channel', 'operator' => 'in', 'value' => ['amazon', 'noon']],
                    ]],
                    'actions' => [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['marketplace']]],
                ],
            ],
        ];
    }
}
