<?php

namespace App\Http\Controllers;

use App\Models\Manifest;
use App\Models\Shipment;
use App\Services\Shipping\LabelBatchBuilder;
use Illuminate\Http\Request;

/**
 * Bulk print (spec 04 §4.4). Packing slips build into one printable HTML document synchronously
 * (dependency-free); carrier PDF-label merging is deferred until a PDF toolchain exists.
 */
class ShipmentBatchController extends Controller
{
    public function __construct(private readonly LabelBatchBuilder $builder)
    {
    }

    public function packingSlips(Request $request)
    {
        $data = $request->validate([
            'shipment_ids' => ['nullable', 'array', 'max:100'],
            'shipment_ids.*' => ['integer'],
            'manifest_id' => ['nullable', 'integer'],
        ]);

        $orgId = $request->header('X-Organization-Id');
        $ids = $data['shipment_ids'] ?? [];

        if (! empty($data['manifest_id'])) {
            $manifest = Manifest::where('organization_id', $orgId)->findOrFail($data['manifest_id']);
            $ids = $manifest->shipments()->pluck('id')->all();
        }

        $shipments = Shipment::where('organization_id', $orgId)->whereIn('id', $ids)->orderBy('id')->get();
        abort_if($shipments->isEmpty(), 422, 'No shipments to print.');

        return response($this->builder->packingSlipsHtml($shipments), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'inline; filename="packing-slips.html"',
        ]);
    }
}
