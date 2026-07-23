<?php

/**
 * Shipping configuration (spec 04). The credential schemas document the shape of
 * carrier_accounts.credentials per carrier and are validated by a CredentialSchema before a probe.
 * Slice 1 ships the `manual` carrier (no credentials); real carriers are added with their drivers.
 */
return [
    // Volumetric-weight divisor (cm³ → kg). The region's carriers use 5000.
    'volumetric_divisor' => 5000,

    // Default rate ranking: 'cheapest' | 'fastest' | 'preferred_carrier_first'.
    'rate_ranking' => 'cheapest',

    // How long a fetched rate is honoured before it must be re-shopped.
    'rate_ttl_minutes' => 30,

    // Durable storage disk for label artefacts (§0 assumption #4). Defaults to local in dev.
    'labels_disk' => env('SHIPPING_LABELS_DISK', 'local'),

    'carriers' => [
        'manual' => [
            'label' => 'Manual / other carrier',
            'credentials' => [], // none — the merchant enters AWBs by hand
            'capabilities' => ['cod'],
        ],
        // Real carrier credential shapes (documented now, wired in their slices):
        'aramex' => ['credentials' => ['username', 'password', 'account_number', 'account_pin', 'account_entity', 'account_country_code']],
        'smsa' => ['credentials' => ['passkey']],
        'naqel' => ['credentials' => ['client_id', 'password']],
        'jnt' => ['credentials' => ['api_account', 'private_key', 'customer_code', 'country_code']],
        'torod' => ['credentials' => ['api_token']],
        'dhl' => ['credentials' => ['api_key', 'api_secret', 'account_number']],
        'fedex' => ['credentials' => ['client_id', 'client_secret', 'account_number']],
    ],
];
