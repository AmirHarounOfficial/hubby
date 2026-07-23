<?php

return [
    // Persist non-matching runs too (10×'s the audit table — off by default; on for debugging).
    'log_non_matches' => env('AUTOMATION_LOG_NON_MATCHES', false),

    // HMAC secret for signing outbound call_webhook requests, so receivers can verify the payload.
    // Falls back to a key derived from APP_KEY so signatures are stable without extra config.
    'webhook_secret' => env('AUTOMATION_WEBHOOK_SECRET'),

    // Carrier codes the assign_carrier action is allowed to set (A3). Empty = allow any.
    'carriers' => array_filter(explode(',', (string) env('AUTOMATION_CARRIERS', ''))),
];
