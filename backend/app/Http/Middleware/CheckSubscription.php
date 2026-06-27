<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Organization;

class CheckSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization-Id');

        if (!$organizationId) {
            return $next($request); // Should be caught by EnsureOrganizationMember, but safe fallback
        }

        $organization = Organization::find($organizationId);

        if (!$organization) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        $subscription = $organization->subscription;

        if (!$subscription || ($subscription->status === 'cancelled' && $subscription->ends_at < now())) {
            return response()->json(['message' => 'Subscription required'], 402);
        }

        if ($subscription->status === 'past_due') {
            // Allow access but maybe add a warning in response (Phase 2)
        }

        return $next($request);
    }
}
