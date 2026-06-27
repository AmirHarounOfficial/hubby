<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->applyFilters($request);

        $orders = $query->with('store')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($orders);
    }

    private function applyFilters(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $query = Order::whereHas('store', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        });

        // Filters
        if ($request->has('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        if ($request->has('platform') && $request->platform !== 'All') {
            $query->whereHas('store', function ($q) use ($request) {
                $q->where('platform', $request->platform);
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('external_id', 'like', "%$search%")
                  ->orWhere('customer_name', 'like', "%$search%")
                  ->orWhere('customer_email', 'like', "%$search%");
            });
        }

        return $query;
    }

    public function show(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $order = Order::whereHas('store', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->with(['store', 'items'])->findOrFail($id);

        return response()->json($order);
    }

    public function update(Request $request, $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $order = Order::whereHas('store', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->findOrFail($id);

        $request->validate([
            'status' => 'required|string',
        ]);

        $oldStatus = $order->status;
        $order->update(['status' => $request->status]);

        // Sync back to platform if cancelled
        if (strtolower($request->status) === 'cancelled' && strtolower($oldStatus) !== 'cancelled') {
            try {
                $service = \App\Services\Integrations\IntegrationFactory::make($order->store->platform);
                $service->cancelOrder($order->store, $order->external_id);
            } catch (\Exception $e) {
                \Log::error("Failed to sync cancellation for order {$order->id}: " . $e->getMessage());
            }
        }
        
        return response()->json($order);
    }

    public function export(Request $request)
    {
        $query = $this->applyFilters($request);
        $orders = $query->with('store')->get();

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=orders.csv",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['Order ID', 'Platform', 'Status', 'Total', 'Currency', 'Customer', 'Email', 'Date'];

        $callback = function() use($orders, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($orders as $order) {
                fputcsv($file, [
                    $order->external_id,
                    $order->store->platform,
                    $order->status,
                    $order->total,
                    $order->currency,
                    $order->customer_name,
                    $order->customer_email,
                    $order->created_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
