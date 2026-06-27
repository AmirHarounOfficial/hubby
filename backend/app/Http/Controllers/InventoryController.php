<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\InventoryLog;
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

        DB::transaction(function () use ($product, $request) {
            if ($request->variant_id) {
                $variant = \App\Models\ProductVariant::where('product_id', $product->id)->findOrFail($request->variant_id);
                $variant->increment('stock', $request->change);
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

        // TODO: Dispatch job to push new inventory to all connected stores
        
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
