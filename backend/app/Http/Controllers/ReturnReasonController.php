<?php

namespace App\Http\Controllers;

use App\Models\ReturnReason;
use Illuminate\Http\Request;

/** Return reasons available to an org: the global taxonomy plus the org's own (spec 03 §5.5). */
class ReturnReasonController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');

        return response()->json(
            ReturnReason::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
                ->orderBy('group')
                ->orderBy('sort_order')
                ->get()
        );
    }
}
