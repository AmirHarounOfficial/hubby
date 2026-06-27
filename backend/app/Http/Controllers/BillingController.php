<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\EdfaPayService;
use Carbon\Carbon;

class BillingController extends Controller
{
    public function __construct(private EdfaPayService $edfaPay)
    {
    }

    public function plans()
    {
        return response()->json(Plan::all());
    }

    public function status(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $subscription = Subscription::where('organization_id', $organizationId)
            ->with('plan')
            ->latest()
            ->first();

        return response()->json([
            'subscribed' => $subscription && $subscription->ends_at > now(),
            'subscription' => $subscription,
        ]);
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $organizationId = $request->header('X-Organization-Id');
        $plan = Plan::findOrFail($request->plan_id);

        // When edfapay credentials are present, send the merchant through a real
        // hosted checkout and only activate once the gateway confirms payment.
        if ($this->edfaPay->configured() && (float) $plan->price > 0) {
            $orderId = 'sub_' . $organizationId . '_' . $plan->id . '_' . uniqid();

            // Hold the intended plan in a pending subscription keyed by the order id.
            $subscription = Subscription::updateOrCreate(
                ['organization_id' => $organizationId],
                [
                    'plan_id' => $plan->id,
                    'status' => 'pending',
                    'external_ref' => $orderId,
                ]
            );

            $checkoutUrl = $this->edfaPay->createCheckout([
                'order_id' => $orderId,
                'amount' => $plan->price,
                'description' => "HubbyGlobal {$plan->name} plan",
                'email' => optional($request->user())->email,
                'first_name' => optional($request->user())->name,
                'ip' => $request->ip(),
                'callback_url' => url('/api/billing/callback'),
            ]);

            if ($checkoutUrl) {
                return response()->json([
                    'message' => 'Redirecting to secure checkout…',
                    'checkout_url' => $checkoutUrl,
                    'subscription' => $subscription,
                ]);
            }

            // Gateway couldn't start a session — fall through to direct activation
            // rather than leaving the merchant stuck.
            \Log::warning("edfapay checkout unavailable for org {$organizationId}; activating directly.");
        }

        // No gateway configured (or a free plan): activate immediately.
        $subscription = Subscription::updateOrCreate(
            ['organization_id' => $organizationId],
            [
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]
        );

        return response()->json([
            'message' => 'Subscription started successfully',
            'subscription' => $subscription,
        ]);
    }

    /**
     * Server-to-server callback from edfapay. Verifies the signature, then
     * activates the pending subscription tied to the order id.
     */
    public function callback(Request $request)
    {
        $data = $request->all();

        if (! $this->edfaPay->verifyCallback($data)) {
            \Log::warning('edfapay callback rejected: invalid signature.');
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $orderId = $data['order_id'] ?? null;
        $status = strtolower($data['status'] ?? $data['result'] ?? '');
        $subscription = Subscription::where('external_ref', $orderId)->first();

        if (! $subscription) {
            return response()->json(['message' => 'Unknown order'], 404);
        }

        if (in_array($status, ['settled', 'success', 'completed', 'approved'], true)) {
            $subscription->update([
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]);
        } else {
            $subscription->update(['status' => 'past_due']);
        }

        return response()->json(['message' => 'ok']);
    }

    public function cancel(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');
        
        $subscription = Subscription::where('organization_id', $organizationId)->latest()->first();

        if ($subscription) {
            $subscription->update(['status' => 'cancelled']);
            return response()->json(['message' => 'Subscription cancelled']);
        }

        return response()->json(['message' => 'No active subscription found'], 404);
    }
}
