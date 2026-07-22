<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Store;
use App\Models\User;
use App\Services\Integrations\AmazonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Defect #8: verifies the SigV4 signing is actually wired into the SP-API HTTP client — present
 * when IAM credentials are configured, absent (LWA-token-only fallback) when they are not.
 */
class AmazonSigningTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $user = User::factory()->create();
        $org = $this->makeOrganization($user);
        // 'salla' rather than 'amazon': the enum-widening migrations are MySQL-only, so SQLite's
        // test schema still rejects 'amazon'. AmazonService keys off the integration + config, not
        // store.platform, so the platform value is immaterial to what we're verifying here.
        $store = Store::create([
            'organization_id' => $org->id,
            'name' => 'Amazon Store',
            'platform' => 'salla',
            'status' => 'connected',
        ]);
        Integration::create([
            'store_id' => $store->id,
            'access_token' => 'Atza|lwa-access-token',
            'refresh_token' => 'Atzr|lwa-refresh-token',
        ]);

        return $store->fresh('integration');
    }

    public function test_requests_are_sigv4_signed_when_iam_credentials_are_configured(): void
    {
        config([
            'services.amazon.aws_access_key_id' => 'AKIDEXAMPLE',
            'services.amazon.aws_secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'services.amazon.marketplace_id' => 'ATVPDKIKX0DER',
        ]);

        Http::fake(['*' => Http::response(['payload' => ['Orders' => []]], 200)]);

        (new AmazonService())->fetchOrders($this->store());

        Http::assertSent(function ($request) {
            return str_starts_with($request->header('Authorization')[0] ?? '', 'AWS4-HMAC-SHA256 ')
                && ! empty($request->header('x-amz-date'))
                // The LWA token still rides along and is itself part of the signed headers.
                && $request->header('x-amz-access-token')[0] === 'Atza|lwa-access-token'
                && str_contains($request->header('Authorization')[0], 'x-amz-access-token');
        });
    }

    public function test_requests_are_unsigned_when_no_iam_credentials_are_present(): void
    {
        config([
            'services.amazon.aws_access_key_id' => null,
            'services.amazon.aws_secret_access_key' => null,
            'services.amazon.marketplace_id' => 'ATVPDKIKX0DER',
        ]);

        Http::fake(['*' => Http::response(['payload' => ['Orders' => []]], 200)]);

        (new AmazonService())->fetchOrders($this->store());

        Http::assertSent(fn ($request) => empty($request->header('Authorization'))
            && $request->header('x-amz-access-token')[0] === 'Atza|lwa-access-token');
    }
}
