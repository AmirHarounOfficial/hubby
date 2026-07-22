<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\InventoryLog;
use App\Jobs\PushInventoryJob;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $inventory = Product::where('organization_id', $organizationId)
            ->with(['variants', 'stores'])
            ->get();

        return response()->json($inventory);
    }

    public function adjust(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'change' => 'required|integer',
            'reason' => 'required|string',
        ]);

        $organizationId = $request->header('X-Organization-Id');
        $product = Product::where('organization_id', $organizationId)->findOrFail($request->product_id);

        $adjustedVariant = null;

        DB::transaction(function () use ($product, $request, &$adjustedVariant) {
            if ($request->variant_id) {
                $adjustedVariant = ProductVariant::where('product_id', $product->id)->findOrFail($request->variant_id);
                $adjustedVariant->increment('stock', $request->change);
            } else {
                $product->increment('stock', $request->change);
            }

            InventoryLog::create([
                'product_id' => $product->id,
                'product_variant_id' => $request->variant_id,
                'change' => $request->change,
                'reason' => $request->reason,
                'source' => 'Manual Adjustment',
            ]);
        });

        // Propagate the new level to every connected channel (defect #4: this used to be a TODO,
        // so manual adjustments never left the dashboard). Channels are addressed by variant SKU,
        // so a product-level adjustment fans out to all of the product's variants.
        $variants = $adjustedVariant
            ? collect([$adjustedVariant->fresh()])
            : $product->variants()->get();

        foreach ($variants as $variant) {
            PushInventoryJob::dispatch($variant);
        }

        return response()->json(['message' => 'Inventory adjusted successfully']);
    }

    public function logs(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $logs = InventoryLog::whereHas('product', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->with(['product', 'variant'])->latest()->paginate(20);

        return response()->json($logs);
    }
}
