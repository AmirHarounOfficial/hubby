<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Services\Returns\ReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The returns queue + RMA lifecycle actions (spec 03 §5.5). Org-scoped via org.member. Domain guard
 * failures (over-return, marketplace-managed, illegal transition) surface as 422s rather than 500s.
 */
class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $service)
    {
    }

    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');

        $returns = ReturnRequest::where('organization_id', $organizationId)
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->get('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->get('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('rma_number', 'like', "%{$s}%")
                ->orWhere('customer_name', 'like', "%{$s}%")
                ->orWhere('customer_email', 'like', "%{$s}%")))
            ->withCount('items')
            ->latest('id')
            ->paginate(20);

        return response()->json($returns);
    }

    public function show(Request $request, int $id)
    {
        $rma = $this->find($request, $id);
        $rma->load(['items', 'events' => fn ($q) => $q->latest('id'), 'order:id,external_id,total,currency', 'store:id,platform']);

        // Tell the dashboard whether issuing the refund will also push it to the channel, so it can
        // label the action honestly ("Refund on Shopify") instead of implying a local-only record.
        $platform = strtolower((string) $rma->store?->platform);
        $refund = \App\Models\Refund::where('return_request_id', $rma->id)->latest('id')->first();

        return response()->json(array_merge($rma->toArray(), [
            'platform' => $platform,
            'can_push_refund' => in_array('refund', config("returns.capabilities.{$platform}", []), true),
            'refund' => $refund ? $refund->only(['status', 'external_id', 'gateway', 'failure_reason', 'processed_at']) : null,
        ]));
    }

    public function store(Request $request)
    {
        $organizationId = (int) $request->header('X-Organization-Id');

        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'reason_code' => ['nullable', 'string', 'max:48'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.reason_code' => ['nullable', 'string', 'max:48'],
        ]);

        $order = Order::whereHas('store', fn ($q) => $q->where('organization_id', $organizationId))
            ->with('items', 'store')
            ->findOrFail($data['order_id']);

        return $this->guard(function () use ($order, $data, $request) {
            $rma = $this->service->create($order, $data['lines'], array_filter([
                'reason_code' => $data['reason_code'] ?? null,
                'created_by_user_id' => $request->user()?->id,
            ]));

            return response()->json($rma->load('items'), 201);
        });
    }

    public function approve(Request $request, int $id)
    {
        $rma = $this->find($request, $id);
        $data = $request->validate(['quantities' => ['nullable', 'array']]);

        return $this->guard(fn () => response()->json(
            $this->service->approve($rma, $data['quantities'] ?? [], $request->user()?->id)->load('items')
        ));
    }

    public function reject(Request $request, int $id)
    {
        $rma = $this->find($request, $id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->guard(fn () => response()->json(
            $this->service->reject($rma, $data['reason'], $request->user()?->id)
        ));
    }

    public function ship(Request $request, int $id)
    {
        return $this->guard(fn () => response()->json($this->service->ship($this->find($request, $id))));
    }

    public function receive(Request $request, int $id)
    {
        $rma = $this->find($request, $id);
        $data = $request->validate(['quantities' => ['nullable', 'array']]);

        return $this->guard(fn () => response()->json(
            $this->service->receive($rma, $data['quantities'] ?? [])->load('items')
        ));
    }

    public function inspect(Request $request, int $id)
    {
        $rma = $this->find($request, $id);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.return_item_id' => ['required', 'integer'],
            'items.*.condition' => ['required', Rule::in(ReturnItem::CONDITIONS)],
            'items.*.disposition' => ['required', Rule::in(ReturnItem::DISPOSITIONS)],
            'items.*.quantity_restock' => ['nullable', 'integer', 'min:0'],
            'items.*.quantity_scrap' => ['nullable', 'integer', 'min:0'],
            'items.*.note' => ['nullable', 'string'],
        ]);

        return $this->guard(function () use ($rma, $data) {
            foreach ($data['items'] as $row) {
                $item = $rma->items()->findOrFail($row['return_item_id']);
                $this->service->inspectLine(
                    $item,
                    $row['condition'],
                    $row['disposition'],
                    (int) ($row['quantity_restock'] ?? 0),
                    (int) ($row['quantity_scrap'] ?? 0),
                    $row['note'] ?? null,
                );
            }

            return response()->json($rma->fresh(['items', 'events']));
        });
    }

    public function refund(Request $request, int $id)
    {
        $rma = $this->find($request, $id);
        $data = $request->validate(['method' => ['nullable', 'string', 'max:24']]);

        return $this->guard(fn () => response()->json(
            $this->service->refund($rma, $data['method'] ?? 'original_payment', $request->user()?->id)->load('items')
        ));
    }

    public function analytics(Request $request, \App\Services\Returns\ReturnsReportService $reports)
    {
        [$from, $to] = \App\Services\Profit\ProfitReportService::defaultRange(
            $request->get('start_date'),
            $request->get('end_date'),
        );

        return response()->json($reports->summary((int) $request->header('X-Organization-Id'), $from, $to));
    }

    private function find(Request $request, int $id): ReturnRequest
    {
        return ReturnRequest::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    /** Run the action; turn a domain guard RuntimeException into a clean 422. */
    private function guard(callable $action)
    {
        try {
            return $action();
        } catch (\App\Exceptions\InvalidReturnTransition $e) {
            // Has its own render() with from/to — let Laravel's handler produce that richer 422.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'RETURN_RULE_VIOLATION'], 422);
        }
    }
}
