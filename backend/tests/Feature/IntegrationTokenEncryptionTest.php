<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationTokenEncryptionTest extends TestCase
{
    use RefreshDatabase;

    // Deliberately not shaped like any real provider token — the value only needs to survive an
    // encrypt/decrypt round-trip, and a realistic prefix trips secret scanners on a test fixture.
    private const PLAIN_ACCESS = 'fake-access-token-for-tests-0001';
    private const PLAIN_REFRESH = 'fake-refresh-token-for-tests-0001';

    private function makeStore(): Store
    {
        $user = User::factory()->create();

        return Store::create([
            'organization_id' => $this->makeOrganization($user)->id,
            'name' => 'Shopify Store',
            'platform' => 'shopify',
            'status' => 'connected',
        ]);
    }

    /** Run the backfill migration the same way `php artisan migrate` would. */
    private function runBackfill(): void
    {
        (require database_path(
            'migrations/2026_07_22_000001_encrypt_existing_integration_tokens.php'
        ))->up();
    }

    /** Insert a row the way it looked before the cast existed: raw plaintext. */
    private function insertPlaintextRow(Store $store): int
    {
        return DB::table('integrations')->insertGetId([
            'store_id' => $store->id,
            'access_token' => self::PLAIN_ACCESS,
            'refresh_token' => self::PLAIN_REFRESH,
            'shop_domain' => 'demo.myshopify.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rawRow(int $id): object
    {
        return DB::table('integrations')->where('id', $id)->first();
    }

    public function test_tokens_written_through_the_model_are_encrypted_at_rest()
    {
        $integration = Integration::create([
            'store_id' => $this->makeStore()->id,
            'access_token' => self::PLAIN_ACCESS,
            'refresh_token' => self::PLAIN_REFRESH,
        ]);

        $raw = $this->rawRow($integration->id);

        $this->assertNotSame(self::PLAIN_ACCESS, $raw->access_token);
        $this->assertNotSame(self::PLAIN_REFRESH, $raw->refresh_token);
        $this->assertSame(self::PLAIN_ACCESS, Crypt::decryptString($raw->access_token));
        $this->assertSame(self::PLAIN_REFRESH, Crypt::decryptString($raw->refresh_token));

        // And reading back through Eloquent is transparent.
        $this->assertSame(self::PLAIN_ACCESS, $integration->fresh()->access_token);
        $this->assertSame(self::PLAIN_REFRESH, $integration->fresh()->refresh_token);
    }

    public function test_migration_encrypts_existing_plaintext_rows()
    {
        $id = $this->insertPlaintextRow($this->makeStore());

        $this->runBackfill();

        $raw = $this->rawRow($id);
        $this->assertNotSame(self::PLAIN_ACCESS, $raw->access_token);
        $this->assertSame(self::PLAIN_ACCESS, Crypt::decryptString($raw->access_token));
        $this->assertSame(self::PLAIN_REFRESH, Crypt::decryptString($raw->refresh_token));

        $integration = Integration::find($id);
        $this->assertSame(self::PLAIN_ACCESS, $integration->access_token);
        $this->assertSame(self::PLAIN_REFRESH, $integration->refresh_token);
    }

    public function test_migration_is_idempotent_and_leaves_updated_at_untouched()
    {
        $id = $this->insertPlaintextRow($this->makeStore());

        $this->runBackfill();

        $afterFirst = $this->rawRow($id);

        $this->runBackfill();
        $this->runBackfill();

        $afterThird = $this->rawRow($id);

        // Already-encrypted rows are skipped, so the ciphertext is byte-identical.
        $this->assertSame($afterFirst->access_token, $afterThird->access_token);
        $this->assertSame($afterFirst->refresh_token, $afterThird->refresh_token);
        $this->assertSame($afterFirst->updated_at, $afterThird->updated_at);
        $this->assertSame(self::PLAIN_ACCESS, Integration::find($id)->access_token);
    }

    public function test_migration_is_safe_on_a_fresh_database_and_with_null_tokens()
    {
        $this->runBackfill(); // No rows at all.

        $integration = Integration::create([
            'store_id' => $this->makeStore()->id,
            'access_token' => null,
            'refresh_token' => null,
        ]);

        $this->runBackfill();

        $this->assertNull($integration->fresh()->access_token);
        $this->assertNull($integration->fresh()->refresh_token);
    }

    public function test_tokens_never_appear_in_json_serialization()
    {
        $integration = Integration::create([
            'store_id' => $this->makeStore()->id,
            'access_token' => self::PLAIN_ACCESS,
            'refresh_token' => self::PLAIN_REFRESH,
        ]);

        $json = $integration->fresh()->toJson();

        $this->assertArrayNotHasKey('access_token', $integration->fresh()->toArray());
        $this->assertArrayNotHasKey('refresh_token', $integration->fresh()->toArray());
        $this->assertStringNotContainsString(self::PLAIN_ACCESS, $json);
        $this->assertStringNotContainsString(self::PLAIN_REFRESH, $json);
    }

    public function test_store_endpoints_do_not_leak_integration_tokens()
    {
        $user = User::factory()->create();
        $organization = $this->makeOrganization($user);
        $user->organizations()->attach($organization->id, ['role' => 'owner']);

        $store = Store::create([
            'organization_id' => $organization->id,
            'name' => 'Shopify Store',
            'platform' => 'shopify',
            'status' => 'connected',
        ]);

        $store->integration()->create([
            'access_token' => self::PLAIN_ACCESS,
            'refresh_token' => self::PLAIN_REFRESH,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
            'X-Organization-Id' => $organization->id,
        ])->getJson('/api/stores');

        $response->assertStatus(200);
        $response->assertDontSee(self::PLAIN_ACCESS);
        $response->assertDontSee(self::PLAIN_REFRESH);
    }

    /** The columns are `text`; make sure ciphertext for a realistic token still fits. */
    public function test_ciphertext_fits_within_the_text_column()
    {
        // Amazon LWA refresh tokens are the longest we handle, comfortably under 1 KB.
        $longToken = 'Atzr|' . str_repeat('X', 1000);

        $integration = Integration::create([
            'store_id' => $this->makeStore()->id,
            'access_token' => $longToken,
        ]);

        $ciphertext = $this->rawRow($integration->id)->access_token;

        // MySQL TEXT caps at 65,535 bytes.
        $this->assertLessThan(65535, strlen($ciphertext));
        $this->assertSame($longToken, $integration->fresh()->access_token);
    }
}
