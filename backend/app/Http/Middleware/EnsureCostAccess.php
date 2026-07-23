<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates cost/margin data (spec 01 §9).
 *
 * COGS, fees and profit are commercially sensitive — a viewer-level teammate (a VA, a support
 * agent) can legitimately work orders without seeing what the business actually makes. Each
 * organization sets the minimum role that may see cost data via `cost_visibility_role`
 * (default: admin); this compares the caller's role in the org against it.
 *
 * Must run after `org.member`, which has already proven the caller belongs to the org.
 */
class EnsureCostAccess
{
    /** Higher number = more privileged. Roles below the org's threshold are denied. */
    private const RANK = [
        'viewer' => 1,
        'admin' => 2,
        'owner' => 3,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization-Id');
        $user = $request->user();

        $organization = Organization::find($organizationId);

        if (! $organization || ! $user) {
            return response()->json(['message' => 'Unauthorized organization access'], 403);
        }

        $member = $organization->users()->where('users.id', $user->id)->first();
        $callerRole = $member?->pivot->role;

        $required = $organization->cost_visibility_role ?? 'admin';

        $callerRank = self::RANK[$callerRole] ?? 0;
        $requiredRank = self::RANK[$required] ?? self::RANK['admin'];

        if ($callerRank < $requiredRank) {
            return response()->json([
                'message' => 'You do not have permission to view cost and profit data.',
            ], 403);
        }

        return $next($request);
    }
}
