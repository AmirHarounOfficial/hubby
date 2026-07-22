<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Defect #5: `product_variants.sku` used to be globally unique, so two tenants could never share
 * a SKU. It is now unique per organization, keeping multi-tenant isolation while matching how the
 * cost/profit engine already keys on `(organization_id, sku)`.
 */
class ProductVariantScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_organizations_can_use_the_same_sku(): void
    {
        [$orgA] = $this->org();
        [$orgB] = $this->org();

        $productA = $this->product($orgA->id);
        $productB = $this->product($orgB->id);

        $variantA = $productA->variants()->create(['sku' => 'SHARED-SKU', 'price' => 10, 'stock' => 1]);
        $variantB = $productB->variants()->create(['sku' => 'SHARED-SKU', 'price' => 20, 'stock' => 2]);

        // Both exist, and each is stamped with its own organization.
        $this->assertSame($orgA->id, $variantA->fresh()->organization_id);
        $this->assertSame($orgB->id, $variantB->fresh()->organization_id);
    }

    public function test_the_same_sku_twice_in_one_organization_is_rejected(): void
    {
        [$org] = $this->org();
        $product = $this->product($org->id);
        $product->variants()->create(['sku' => 'DUP', 'price' => 10, 'stock' => 1]);

        $this->expectException(QueryException::class);
        $product->variants()->create(['sku' => 'DUP', 'price' => 11, 'stock' => 2]);
    }

    public function test_organization_id_is_backfilled_from_the_parent_product(): void
    {
        [$org] = $this->org();
        $product = $this->product($org->id);

        // Created without an explicit organization_id — the model must derive it.
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'AUTO', 'price' => 5, 'stock' => 3]);

        $this->assertSame($org->id, $variant->organization_id);
    }

    /** @return array{0: \App\Models\Organization} */
    private function org(): array
    {
        $user = User::factory()->create();

        return [$this->makeOrganization($user, 'Org '.uniqid())];
    }

    private function product(int $organizationId): Product
    {
        return Product::create([
            'organization_id' => $organizationId,
            'name' => 'Test product',
            'sku' => 'P-'.uniqid(),
            'price' => 100,
            'stock' => 0,
        ]);
    }
}
