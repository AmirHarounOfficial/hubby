<?php

namespace App\Http\Controllers;

use App\Services\Profit\ProfitReportService;
use Illuminate\Http\Request;

/**
 * Profit reporting endpoints (spec 01 §5.5).
 *
 * All reads come from the materialized rollups — nothing recomputes profit on request.
 *
 * NOTE: cost/margin data is commercially sensitive. Role gating (`cost.access`, spec §9) is a
 * follow-up; today these sit behind Sanctum + `org.member` like every other org-scoped endpoint.
 */
class ProfitController extends Controller
{
    public function __construct(private readonly ProfitReportService $reports)
    {
    }

    public function summary(Request $request)
    {
        [$from, $to] = $this->range($request);

        return response()->json(
            $this->reports->summary($this->orgId($request), $from, $to, $this->storeId($request))
        );
    }

    public function timeline(Request $request)
    {
        [$from, $to] = $this->range($request);

        return response()->json(
            $this->reports->timeline($this->orgId($request), $from, $to, $this->storeId($request))
        );
    }

    public function bySku(Request $request)
    {
        [$from, $to] = $this->range($request);
        $limit = min((int) $request->get('limit', 50), 200);

        return response()->json(
            $this->reports->bySku($this->orgId($request), $from, $to, $this->storeId($request), $limit)
        );
    }

    public function byChannel(Request $request)
    {
        [$from, $to] = $this->range($request);

        return response()->json(
            $this->reports->byChannel($this->orgId($request), $from, $to)
        );
    }

    public function coverage(Request $request)
    {
        [$from, $to] = $this->range($request);

        return response()->json(
            $this->reports->coverage($this->orgId($request), $from, $to, $this->storeId($request))
        );
    }

    public function order(Request $request, int $id)
    {
        $profit = $this->reports->forOrder($this->orgId($request), $id);

        if (! $profit) {
            return response()->json([
                'message' => 'No profit has been calculated for this order yet.',
            ], 404);
        }

        return response()->json($profit);
    }

    private function orgId(Request $request): int
    {
        return (int) $request->header('X-Organization-Id');
    }

    private function storeId(Request $request): ?int
    {
        $storeId = $request->get('store_id');

        return $storeId ? (int) $storeId : null;
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        return ProfitReportService::defaultRange(
            $request->get('start_date'),
            $request->get('end_date')
        );
    }
}
