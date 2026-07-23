<?php

namespace App\Services\Shipping;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

/**
 * Bulk print builder (spec 04 §4.4). Merchants print a whole handover in one job.
 *
 * Packing slips are our own HTML artefacts, so a batch is just a single HTML document with a page
 * break between each — dependency-free and fast even for 100. Merging carrier LABEL PDFs into one
 * file genuinely needs a PDF toolchain (the spec flags synchronous 100-PDF merges as a timeout) and
 * is deferred until that dependency is introduced.
 */
class LabelBatchBuilder
{
    public function __construct(private readonly PackingSlipRenderer $slips)
    {
    }

    /** One printable HTML document containing every shipment's packing slip. */
    public function packingSlipsHtml(Collection $shipments): string
    {
        $data = $shipments->map(fn ($s) => $this->slips->slipData($s))->all();

        return View::make('shipping.packing-slips-batch', ['slips' => $data])->render();
    }
}
