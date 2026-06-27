<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Verify the HMAC signature on an inbound platform webhook.
     *
     * Each platform signs the raw request body with a shared secret. We
     * recompute the signature and compare it (constant-time) to the header the
     * platform sent. When no secret is configured for a platform we skip the
     * check so local/dev keeps working, but log a warning so it's visible.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $platform = $request->route('platform');
        $secret = config("services.{$platform}.webhook_secret");

        if (blank($secret)) {
            Log::warning("Webhook signature not verified for [{$platform}]: no webhook_secret configured.");
            return $next($request);
        }

        $raw = $request->getContent();

        [$header, $expected] = match ($platform) {
            'shopify' => [
                'X-Shopify-Hmac-Sha256',
                base64_encode(hash_hmac('sha256', $raw, $secret, true)),
            ],
            'salla' => [
                'X-Salla-Signature',
                hash_hmac('sha256', $raw, $secret),
            ],
            'woocommerce' => [
                'X-WC-Webhook-Signature',
                base64_encode(hash_hmac('sha256', $raw, $secret, true)),
            ],
            default => [null, null],
        };

        if ($header === null) {
            // Unknown platform — nothing to verify against.
            return $next($request);
        }

        $provided = (string) $request->header($header, '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning("Rejected {$platform} webhook: invalid signature.");
            return response()->json(['message' => 'Invalid webhook signature'], 401);
        }

        return $next($request);
    }
}
