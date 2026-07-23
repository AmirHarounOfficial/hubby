<?php

namespace Tests\Feature;

use App\Models\CityAlias;
use App\Models\User;
use App\Services\Shipping\AddressValidator;
use App\Services\Shipping\CityNormalizer;
use Database\Seeders\CityAliasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** City normalization + three-tier address validation (spec 04 §4.8). */
class AddressValidationTest extends TestCase
{
    use RefreshDatabase;

    private CityNormalizer $cities;
    private AddressValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CityAliasSeeder::class);
        $this->cities = app(CityNormalizer::class);
        $this->validator = app(AddressValidator::class);
    }

    public function test_english_and_arabic_spellings_map_to_one_canonical_key(): void
    {
        $this->assertSame('riyadh', $this->cities->normalize('Riyadh', 'SA')['canonical']);
        $this->assertSame('riyadh', $this->cities->normalize('الرياض', 'SA')['canonical']);
        $this->assertSame('riyadh', $this->cities->normalize('Ar Riyadh', 'SA')['canonical']);
        $this->assertSame('jeddah', $this->cities->normalize('Jiddah', 'SA')['canonical']);
        $this->assertSame('dubai', $this->cities->normalize('دبي', 'AE')['canonical']);
    }

    public function test_a_latin_typo_is_fuzzy_matched(): void
    {
        $res = $this->cities->normalize('Riyad', 'SA'); // seeded alias, exact
        $this->assertSame('riyadh', $res['canonical']);

        $typo = $this->cities->normalize('Jeddha', 'SA'); // 1 transposition from jeddah
        $this->assertSame('jeddah', $typo['canonical']);
        $this->assertSame('fuzzy', $typo['method']);
    }

    public function test_an_unknown_city_does_not_match(): void
    {
        $this->assertFalse($this->cities->normalize('Atlantis', 'SA')['matched']);
    }

    public function test_a_per_org_alias_overrides_the_global_seed(): void
    {
        $org = $this->makeOrganization(User::factory()->create());
        CityAlias::create(['organization_id' => $org->id, 'country_code' => 'SA', 'alias' => 'hq town', 'canonical' => 'riyadh']);
        $this->assertSame('riyadh', $this->cities->normalize('HQ Town', 'SA', $org->id)['canonical']);
        $this->assertFalse($this->cities->normalize('HQ Town', 'SA')['matched']); // not global
    }

    public function test_structural_validation_normalizes_phone_and_arabic_digits(): void
    {
        $res = $this->validator->validate([
            'name' => 'Sara', 'phone' => '٠٥٥١٢٣٤٥٦٧', 'city' => 'Riyadh', 'district' => 'Al Olaya', 'country_code' => 'SA',
        ]);

        $this->assertTrue($res['is_valid']);
        $this->assertSame('+966551234567', $res['normalized']['phone']); // Arabic digits → ASCII, E.164
        $this->assertSame('riyadh', $res['normalized']['city_normalized']);
    }

    public function test_missing_required_fields_are_errors_that_block(): void
    {
        $res = $this->validator->validate(['city' => 'Riyadh', 'country_code' => 'SA']); // no name/phone/district

        $this->assertFalse($res['is_valid']);
        $fields = collect($res['notes'])->where('severity', 'error')->pluck('field')->all();
        $this->assertContains('name', $fields);
        $this->assertContains('phone', $fields);
        $this->assertContains('district', $fields);
    }

    public function test_an_unrecognised_city_is_a_warning_not_a_block(): void
    {
        $res = $this->validator->validate([
            'name' => 'Sara', 'phone' => '0551234567', 'city' => 'Nowhereville', 'district' => 'X', 'country_code' => 'SA',
        ]);

        $this->assertTrue($res['is_valid']); // warnings don't block
        $this->assertSame('warning', collect($res['notes'])->firstWhere('field', 'city')['severity']);
    }

    public function test_the_validate_endpoint_returns_notes(): void
    {
        $user = User::factory()->create();
        $org = $this->makeOrganization($user);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Sanctum::actingAs($user);

        $this->postJson('/api/addresses/validate', [
            'name' => 'Sara', 'phone' => '0551234567', 'city' => 'الرياض', 'district' => 'العليا', 'country_code' => 'SA',
        ], ['X-Organization-Id' => $org->id])
            ->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('normalized.city_normalized', 'riyadh');
    }
}
