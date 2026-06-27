<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Jobs\SyncProductsJob;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $query = Product::where('organization_id', $organizationId);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('sku', 'like', "%$search%");
            });
        }

        $products = $query->with(['stores', 'variants'])
            ->paginate($request->get('per_page', 20));

        return response()->json($products);
    }

    public function show(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        $product = Product::where('organization_id', $organizationId)
            ->with(['variants', 'platformProducts.store', 'category'])
            ->findOrFail($id);

        return response()->json($product);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'string',
            'price' => 'numeric',
            'sku' => 'string',
            'description' => 'string|nullable',
            'stock' => 'integer',
            'category_id' => 'nullable|exists:categories,id',
            'variants' => 'array',
            'store_ids' => 'array',
            'store_ids.*' => 'exists:stores,id',
        ]);

        $organizationId = $request->header('X-Organization-Id');
        $product = Product::where('organization_id', $organizationId)->findOrFail($id);

        return \DB::transaction(function() use ($request, $product) {
            $product->update($request->only([
                'name', 'price', 'sku', 'description', 'stock', 'image_url', 'category_id'
            ]));

            if ($request->has('variants')) {
                // Simple approach: remove old variants and create new ones
                // Or better: update existing ones if ID is provided
                $existingVariantIds = [];
                foreach ($request->variants as $variantData) {
                    if (isset($variantData['id'])) {
                        $variant = $product->variants()->findOrFail($variantData['id']);
                        $variant->update($variantData);
                        $existingVariantIds[] = $variant->id;
                    } else {
                        $variant = $product->variants()->create($variantData);
                        $existingVariantIds[] = $variant->id;
                    }
                }
                // Optional: $product->variants()->whereNotIn('id', $existingVariantIds)->delete();
            }

            // Sync which stores this product is linked to (platform_products).
            if ($request->has('store_ids')) {
                $storeIds = $request->store_ids;
                foreach ($product->variants()->get() as $variant) {
                    foreach ($storeIds as $storeId) {
                        \App\Models\PlatformProduct::firstOrCreate(
                            ['store_id' => $storeId, 'product_variant_id' => $variant->id],
                            ['external_id' => 'pending']
                        );
                    }
                    \App\Models\PlatformProduct::where('product_variant_id', $variant->id)
                        ->whereNotIn('store_id', count($storeIds) ? $storeIds : [0])
                        ->delete();
                }
            }

            return response()->json([
                'message' => 'Product updated successfully', 
                'product' => $product->load('variants')
            ]);
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'price' => 'required|numeric',
            'sku' => 'required|string',
            'description' => 'string|nullable',
            'stock' => 'integer',
            'variants' => 'array',
            'variants.*.name' => 'required|string',
            'variants.*.sku' => 'required|string',
            'variants.*.price' => 'required|numeric',
            'variants.*.stock' => 'integer',
        ]);

        $organizationId = $request->header('X-Organization-Id');
        
        return \DB::transaction(function() use ($request, $organizationId) {
            $product = Product::create([
                'organization_id' => $organizationId,
                'name' => $request->name,
                'sku' => $request->sku,
                'price' => $request->price,
                'stock' => $request->stock,
                'description' => $request->description,
                'image_url' => $request->image_url,
                'category_id' => $request->category_id,
            ]);

            if ($request->has('variants')) {
                foreach ($request->variants as $variantData) {
                    $variant = $product->variants()->create($variantData);
                    
                    // Link to selected stores
                    if ($request->has('store_ids')) {
                        foreach ($request->store_ids as $storeId) {
                            \App\Models\PlatformProduct::create([
                                'product_variant_id' => $variant->id,
                                'store_id' => $storeId,
                                'external_id' => 'pending', // Will be updated on actual sync
                                'sync_enabled' => true
                            ]);
                        }
                    }
                }
            }

            return response()->json(['message' => 'Product created successfully', 'product' => $product->load('variants')]);
        });
    }

    public function destroy(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        $product = Product::where('organization_id', $organizationId)->findOrFail($id);
        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }

    public function sync(Request $request)
    {
        $request->validate(['product_ids' => 'array']);

        $organizationId = $request->header('X-Organization-Id');

        $stores = \App\Models\Store::where('organization_id', $organizationId)->get();

        foreach ($stores as $store) {
            SyncProductsJob::dispatch($store);
        }

        $scope = $request->filled('product_ids')
            ? count($request->product_ids) . ' selected product(s)'
            : 'all products';

        return response()->json([
            'message' => "Sync triggered for {$scope} across " . $stores->count() . ' store(s)',
        ]);
    }

    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $url = config('app.url') . '/storage/' . $path;
            
            return response()->json([
                'url' => $url,
                'path' => $path
            ]);
        }

        return response()->json(['message' => 'No image uploaded'], 400);
    }

    public function togglePlatformSync(Request $request, $id)
    {
        $platformProduct = \App\Models\PlatformProduct::findOrFail($id);
        $platformProduct->update([
            'sync_enabled' => !$platformProduct->sync_enabled
        ]);

        return response()->json([
            'message' => 'Sync status updated',
            'sync_enabled' => $platformProduct->sync_enabled
        ]);
    }
}
