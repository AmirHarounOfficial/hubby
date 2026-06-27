<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationMember
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
            return response()->json(['message' => 'Organization ID header missing'], 400);
        }

        $user = $request->user();

        if (!$user->organizations()->where('organizations.id', $organizationId)->exists()) {
            return response()->json(['message' => 'Unauthorized organization access'], 403);
        }

        return $next($request);
    }
}
