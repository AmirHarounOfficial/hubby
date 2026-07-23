<?php

namespace App\Services\Automation;

use Illuminate\Support\Facades\Http;

/**
 * Sends a `call_webhook` action's HTTP request (spec 02 §3.6): HMAC-signed so the receiver can
 * verify it, SSRF-guarded so a rule can't be turned into a probe of internal infrastructure, and
 * carrying an Idempotency-Key so a retry is safe on the receiver's side too.
 */
class WebhookDispatcher
{
    /**
     * @throws \RuntimeException on an unsafe URL (caller records the action as failed).
     * @return array{status: int}
     */
    public function send(string $url, string $method, array $payload, string $idempotencyKey, array $extraHeaders = []): array
    {
        $this->assertSafeUrl($url);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $this->secret());

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'X-Hubby-Signature' => 'sha256='.$signature,
            'Idempotency-Key' => $idempotencyKey,
            'User-Agent' => 'Hubby-Automation/1.0',
        ], $extraHeaders);

        $response = Http::withHeaders($headers)
            ->timeout(10)
            ->withBody($body, 'application/json')
            ->send(strtoupper($method) === 'PUT' ? 'PUT' : 'POST', $url);

        return ['status' => $response->status()];
    }

    private function secret(): string
    {
        return config('automation.webhook_secret') ?: hash('sha256', 'automation:'.config('app.key'));
    }

    /**
     * Reject anything that isn't public HTTPS. Blocks http, non-standard hosts, and any host that
     * resolves to a private / loopback / link-local / reserved address (SSRF).
     */
    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new \RuntimeException('webhook_url_must_be_https');
        }

        $host = $parts['host'];
        // A literal IP is checked directly; a hostname is resolved.
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new \RuntimeException('webhook_host_unresolvable');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('webhook_host_not_public');
            }
        }
    }
}
