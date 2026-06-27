<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function dashboard(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        $days = $request->get('days', 30);
        $startDate = $request->get('start_date', Carbon::now()->subDays($days));
        $endDate = $request->get('end_date', Carbon::now());
        
        $cacheKey = "analytics_dashboard_{$organizationId}_{$days}_{$startDate}_{$endDate}";

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 900, function() use ($request, $organizationId, $startDate, $endDate) {
            $scoped = fn () => Order::whereHas('store', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });

            $totalRevenue = (clone $scoped())->whereBetween('created_at', [$startDate, $endDate])->sum('total');
            $totalOrders = (clone $scoped())->whereBetween('created_at', [$startDate, $endDate])->count();
            $activeProducts = Product::where('organization_id', $organizationId)->count();

            // Previous equal-length period, for trend deltas.
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $length = max(1, $start->diffInDays($end));
            $prevStart = $start->copy()->subDays($length);
            $prevRevenue = (clone $scoped())->whereBetween('created_at', [$prevStart, $start])->sum('total');
            $prevOrders = (clone $scoped())->whereBetween('created_at', [$prevStart, $start])->count();

            // Only report a delta when the prior period has a real baseline,
            // otherwise a near-zero denominator yields absurd percentages.
            $hasBaseline = $prevOrders >= 3;
            $pct = fn ($current, $previous) => ($hasBaseline && $previous > 0)
                ? round((($current - $previous) / $previous) * 100, 1)
                : null;

            return response()->json([
                'total_revenue' => (float) $totalRevenue,
                'total_orders' => $totalOrders,
                'active_products' => $activeProducts,
                'avg_order_value' => $totalOrders > 0 ? (float) ($totalRevenue / $totalOrders) : 0,
                'revenue_change' => $pct($totalRevenue, $prevRevenue),
                'orders_change' => $pct($totalOrders, $prevOrders),
            ]);
        });
    }

    public function byPlatform(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        $cacheKey = "analytics_by_platform_{$organizationId}";

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 900, function() use ($organizationId) {
            $data = Order::whereHas('store', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->join('stores', 'orders.store_id', '=', 'stores.id')
                ->select('stores.platform', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as revenue'))
                ->groupBy('stores.platform')
                ->get();

            return response()->json($data);
        });
    }

    public function topProducts(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        $cacheKey = "analytics_top_products_{$organizationId}";

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 900, function() use ($organizationId) {
            $data = \App\Models\OrderItem::whereHas('order.store', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->select('name', DB::raw('SUM(quantity) as units_sold'), DB::raw('SUM(price * quantity) as revenue'))
                ->groupBy('name')
                ->orderByDesc('revenue')
                ->limit(10)
                ->get();

            return response()->json($data);
        });
    }

    public function ordersTimeline(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        $days = $request->get('days', 30);
        $startDate = $request->get('start_date', Carbon::now()->subDays($days));
        $endDate = $request->get('end_date', Carbon::now());
        
        $cacheKey = "analytics_timeline_{$organizationId}_{$days}_{$startDate}_{$endDate}";

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 900, function() use ($organizationId, $startDate, $endDate) {
            $data = Order::whereHas('store', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->whereBetween('created_at', [$startDate, $endDate])
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as orders'), DB::raw('SUM(total) as revenue'))
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return response()->json($data);
        });
    }

    public function topCustomers(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        $cacheKey = "analytics_top_customers_{$organizationId}";

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 900, function() use ($organizationId) {
            $data = Order::whereHas('store', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->select('customer_name', 'customer_email', DB::raw('COUNT(*) as orders_count'), DB::raw('SUM(total) as total_spent'))
                ->groupBy('customer_name', 'customer_email')
                ->orderByDesc('total_spent')
                ->limit(5)
                ->get();

            return response()->json($data);
        });
    }
}
