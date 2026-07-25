<?php

namespace App\Services\Warehouse;

use App\Models\Organization;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse bookkeeping (spec 08 §3.1). A single-warehouse merchant should never have to learn the
 * concept, so the first use of any warehouse feature quietly creates a default MAIN.
 */
class WarehouseService
{
    public function ensureDefault(int $organizationId): Warehouse
    {
        $existing = Warehouse::where('organization_id', $organizationId)->where('is_default', true)->first();
        if ($existing) {
            return $existing;
        }

        $name = Organization::find($organizationId)?->name ?? 'Main warehouse';

        return Warehouse::firstOrCreate(
            ['organization_id' => $organizationId, 'code' => 'MAIN'],
            ['name' => $name, 'is_default' => true, 'is_active' => true],
        );
    }

    /** Exactly one default per org (§3.1) — app-enforced, since it's a business rule not a key. */
    public function makeDefault(Warehouse $warehouse): Warehouse
    {
        DB::transaction(function () use ($warehouse) {
            Warehouse::where('organization_id', $warehouse->organization_id)
                ->where('id', '!=', $warehouse->id)
                ->update(['is_default' => false]);
            $warehouse->update(['is_default' => true]);
        });

        return $warehouse->fresh();
    }
}
