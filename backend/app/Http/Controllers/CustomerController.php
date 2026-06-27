<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');

        $query = Order::join('stores', 'orders.store_id', '=', 'stores.id')
            ->where('stores.organization_id', $organizationId);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('customer_name', 'like', "%$search%")
                  ->orWhere('customer_email', 'like', "%$search%");
            });
        }

        if ($request->has('platform') && $request->platform !== 'all') {
            $query->where('stores.platform', $request->platform);
        }

        $customers = $query->select(
            'customer_email',
            DB::raw('MAX(customer_name) as name'),
            DB::raw('COUNT(orders.id) as total_orders'),
            DB::raw('SUM(total) as total_spend'),
            DB::raw('MAX(orders.created_at) as last_order_date'),
            DB::raw('GROUP_CONCAT(DISTINCT stores.platform) as platforms')
        )
        ->groupBy('customer_email')
        ->orderBy('last_order_date', 'desc')
        ->paginate($request->get('per_page', 15));

        return response()->json($customers);
    }

    public function show(Request $request, $email)
    {
        $organizationId = $request->header('X-Organization-Id');

        $orders = Order::whereHas('store', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })
        ->where('customer_email', $email)
        ->with(['store', 'items'])
        ->orderBy('created_at', 'desc')
        ->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        $summary = [
            'email' => $email,
            'name' => $orders->first()->customer_name,
            'total_orders' => $orders->count(),
            'total_spend' => $orders->sum('total'),
            'currency' => $orders->first()->currency,
            'orders' => $orders
        ];

        return response()->json($summary);
    }
}
