<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * edfapay hosted-checkout integration.
 *
 * The gateway is driven entirely from config (`services.edfapay.*`), which reads
 * from the EDFAPAY_* env vars. When the merchant key / password are absent the
 * service reports itself "not configured" so the billing flow can fall back to a
 * simulated activation instead of failing — drop sandbox credentials in .env and
 * real hosted checkout switches on with no code change.
 *
 * Hash convention (per edfapay docs):
 *   hash = sha1(strtoupper(md5(order_id . amount . currency . description . password)))
 */
class EdfaPayService
{
    public function configured(): bool
    {
        return filled(config('services.edfapay.merchant_key'))
            && filled(config('services.edfapay.password'));
    }

    /**
     * Create a hosted-payment session and return the URL to redirect the payer to.
     *
     * @return string|null  Redirect URL, or null if the gateway could not start a session.
     */
    public function createCheckout(array $order): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $merchantKey = config('services.edfapay.merchant_key');
        $currency = $order['currency'] ?? config('services.edfapay.currency');
        $amount = number_format((float) $order['amount'], 2, '.', '');

        $payload = [
            'action' => 'SALE',
            'edfa_merchant_id' => $merchantKey,
            'order_id' => $order['order_id'],
            'order_amount' => $amount,
            'order_currency' => $currency,
            'order_description' => $order['description'] ?? 'HubbyGlobal subscription',
            'req_token' => 'N',
            'payer_first_name' => $order['first_name'] ?? 'HubbyGlobal',
            'payer_last_name' => $order['last_name'] ?? 'Customer',
            'payer_email' => $order['email'] ?? '',
            'payer_phone' => $order['phone'] ?? '',
            'payer_ip' => $order['ip'] ?? '0.0.0.0',
            'term_url_3ds' => $order['callback_url'] ?? '',
            'auth' => 'N',
            'hash' => $this->hash($order['order_id'], $amount, $currency, $order['description'] ?? 'HubbyGlobal subscription'),
        ];

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->post(rtrim(config('services.edfapay.base_url'), '/') . '/payment/post', $payload);

            if ($response->failed()) {
                Log::error('edfapay createCheckout failed: ' . $response->body());
                return null;
            }

            // The gateway returns the hosted-page / 3DS redirect URL.
            return $response->json('redirect_url')
                ?? $response->json('redirect')
                ?? null;
        } catch (\Throwable $e) {
            Log::error('edfapay createCheckout error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify the signature edfapay sends on its server-to-server callback.
     */
    public function verifyCallback(array $data): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $expected = $this->hash(
            $data['order_id'] ?? '',
            $data['order_amount'] ?? '',
            $data['order_currency'] ?? '',
            $data['order_description'] ?? 'HubbyGlobal subscription',
        );

        return isset($data['hash']) && hash_equals($expected, (string) $data['hash']);
    }

    private function hash(string $orderId, string $amount, string $currency, string $description): string
    {
        $password = config('services.edfapay.password');

        return sha1(strtoupper(md5($orderId . $amount . $currency . $description . $password)));
    }
}
