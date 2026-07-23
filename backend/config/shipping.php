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
        'dhl' => [
            'label' => 'DHL Express',
            'credentials' => ['api_key', 'api_secret', 'account_number'],
            'capabilities' => ['rates', 'multi_package', 'pickup', 'cancel', 'address_validation', 'zpl'],
        ],
        'aramex' => [
            'label' => 'Aramex',
            'credentials' => ['username', 'password', 'account_number', 'account_pin', 'account_entity', 'account_country_code'],
            'capabilities' => ['rates', 'cod', 'pickup', 'cancel', 'multi_package', 'address_validation'],
        ],
        'smsa' => [
            'label' => 'SMSA Express',
            // mode = 'secom_soap' (legacy, passkey) | 'rest' (newer, api_key). Fill the one you use.
            'credentials' => ['mode', 'passkey', 'api_key'],
            'capabilities' => ['cod', 'cancel'],
        ],
        'naqel' => [
            'label' => 'Naqel Express',
            'credentials' => ['client_id', 'password'],
            'capabilities' => ['cod', 'cancel', 'pickup'],
        ],
        'jnt' => [
            'label' => 'J&T Express',
            'credentials' => ['api_account', 'private_key', 'customer_code', 'country_code'],
            'capabilities' => ['cod', 'cancel'],
            // country-fragmented base URLs (spec §6.4) — extend as launch countries are onboarded.
            'countries' => [
                'sa' => 'https://api.jtexpress.sa',
                'eg' => 'https://api.jtexpress.com.eg',
                'ae' => 'https://api.jtexpress.ae',
            ],
        ],
        'torod' => [
            'label' => 'Torod (aggregator)',
            'credentials' => ['api_token'],
            'capabilities' => ['cod', 'cancel'],
        ],
        'fedex' => [
            'label' => 'FedEx',
            'credentials' => ['client_id', 'client_secret', 'account_number'],
            'capabilities' => ['rates', 'cancel', 'multi_package'],
        ],
    ],
];
