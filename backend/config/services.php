<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ---- Store integrations (fill the *_CLIENT_ID / *_CLIENT_SECRET in .env) ----
    // `webhook_secret` is the signing secret each platform uses to sign inbound
    // webhooks. When set, VerifyWebhookSignature enforces the HMAC; when blank,
    // verification is skipped (dev/local) and a warning is logged.
    'shopify' => [
        'api_key' => env('SHOPIFY_CLIENT_ID'),
        'api_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_CLIENT_SECRET')),
        'redirect' => env('APP_URL') . '/api/oauth/shopify/callback',
    ],

    'salla' => [
        'client_id' => env('SALLA_CLIENT_ID'),
        'client_secret' => env('SALLA_CLIENT_SECRET'),
        'webhook_secret' => env('SALLA_WEBHOOK_SECRET'),
        'redirect' => env('APP_URL') . '/api/oauth/salla/callback',
    ],

    'woocommerce' => [
        'client_id' => env('WOO_CLIENT_ID'),
        'client_secret' => env('WOO_CLIENT_SECRET'),
        'webhook_secret' => env('WOO_WEBHOOK_SECRET'),
    ],

    'zid' => [
        'client_id' => env('ZID_CLIENT_ID'),
        'client_secret' => env('ZID_CLIENT_SECRET'),
        'webhook_secret' => env('ZID_WEBHOOK_SECRET'),
    ],

    // Amazon Selling Partner API (SP-API) — Login with Amazon + SP-API creds.
    'amazon' => [
        'app_id' => env('AMAZON_APP_ID'),
        'client_id' => env('AMAZON_CLIENT_ID'),
        'client_secret' => env('AMAZON_CLIENT_SECRET'),
        'seller_id' => env('AMAZON_SELLER_ID'),
        'marketplace_id' => env('AMAZON_MARKETPLACE_ID'),
        'region' => env('AMAZON_REGION', 'na'),
        'webhook_secret' => env('AMAZON_WEBHOOK_SECRET'),
    ],

    // noon Seller (Partner) API — OAuth2 client creds (hosts are env-driven).
    'noon' => [
        'client_id' => env('NOON_CLIENT_ID'),
        'client_secret' => env('NOON_CLIENT_SECRET'),
        'auth_url' => env('NOON_AUTH_URL', 'https://accounts.noon.partners'),
        'base_url' => env('NOON_BASE_URL', 'https://api.noon.partners'),
        'webhook_secret' => env('NOON_WEBHOOK_SECRET'),
    ],

    // ---- edfapay payment gateway (sandbox-ready; fill in .env to enable) ----
    'edfapay' => [
        'merchant_key' => env('EDFAPAY_MERCHANT_KEY'),
        'password' => env('EDFAPAY_PASSWORD'),
        // Sandbox: https://sandbox.edfapay.com/payment/post  ·  Live: https://api.edfapay.com/payment/post
        'base_url' => env('EDFAPAY_BASE_URL', 'https://sandbox.edfapay.com'),
        'currency' => env('EDFAPAY_CURRENCY', 'SAR'),
    ],

];
