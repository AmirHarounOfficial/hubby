<?php

/**
 * Returns two-way sync capability matrix (spec 03 §5.3).
 *
 * The single source of truth for what each platform can do with a return, read by both the platform
 * services (`supportsReturnCapability`) and the dashboard (so it can label or grey out a "push"
 * action without a round-trip). Capabilities: 'fetch', 'approve', 'reject', 'refund', 'label'.
 *
 * Honest v1 state: only refunds push, and only where a stable non-deprecated API exists today.
 *   - shopify / woocommerce: REST refund endpoints are live and testable now.
 *   - Everything else stays empty until its milestone lands — Shopify *return objects* need a
 *     GraphQL client (M2 follow-up), marketplace mirrors are M4, and labels need Spec 04.
 */
return [
    'capabilities' => [
        'shopify' => ['refund'],
        'woocommerce' => ['refund'],
        'salla' => [],
        'zid' => [],
        'amazon' => [],
        'noon' => [],
        'trendyol' => [],
    ],
];
