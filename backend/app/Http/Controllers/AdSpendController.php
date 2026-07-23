<?php

namespace App\Http\Controllers;

use App\Models\AdSpend;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Advertising spend entry (spec 01 §5.5): manual rows now, CSV import, and — later — the Ads API.
 * Keyed by a deterministic spend_key so re-entering a day's spend updates rather than duplicates.
 */
class AdSpendController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');

        return response()->json(
            AdSpend::where('organization_id', $organizationId)
                ->when($request->get('channel'), fn ($q, $c) => $q->where('channel', $c))
                ->orderByDesc('date')
                ->limit(500)
                ->get()
        );
    }

    public function store(Request $request)
    {
        $organizationId = (int) $request->header('X-Organization-Id');

        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'channel' => ['required', Rule::in(AdSpend::CHANNELS)],
            'campaign_name' => ['nullable', 'string', 'max:191'],
            'campaign_external_id' => ['nullable', 'string', 'max:191'],
            'sku' => ['nullable', 'string', 'max:191'],
            'date' => ['required', 'date'],
            'spend' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'impressions' => ['nullable', 'integer', 'min:0'],
            'clicks' => ['nullable', 'integer', 'min:0'],
        ]);

        $row = $this->upsert($organizationId, $data, $request->user()?->id);

        return response()->json($row, 201);
    }

    public function import(Request $request)
    {
        $organizationId = (int) $request->header('X-Organization-Id');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Could not read the file.'], 422);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return response()->json(['message' => 'Empty file.'], 422);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $imported = 0;
        $skipped = 0;
        while (($cols = fgetcsv($handle)) !== false) {
            $row = array_combine($header, array_pad($cols, count($header), null));
            $channel = strtolower(trim((string) ($row['channel'] ?? '')));
            $date = trim((string) ($row['date'] ?? ''));

            if (! in_array($channel, AdSpend::CHANNELS, true) || $date === '' || strtotime($date) === false) {
                $skipped++;
                continue;
            }

            $this->upsert($organizationId, [
                'store_id' => null,
                'channel' => $channel,
                'campaign_name' => $row['campaign'] ?? $row['campaign_name'] ?? null,
                'campaign_external_id' => $row['campaign_id'] ?? null,
                'sku' => $row['sku'] ?? null,
                'date' => date('Y-m-d', strtotime($date)),
                'spend' => (float) ($row['spend'] ?? 0),
                'currency' => $row['currency'] ?? 'SAR',
            ], $request->user()?->id, 'csv');
            $imported++;
        }
        fclose($handle);

        return response()->json(['imported' => $imported, 'skipped' => $skipped]);
    }

    public function destroy(Request $request, int $id)
    {
        $organizationId = $request->header('X-Organization-Id');
        AdSpend::where('organization_id', $organizationId)->findOrFail($id)->delete();

        return response()->json(['message' => 'Ad spend deleted']);
    }

    private function upsert(int $organizationId, array $data, ?int $userId, string $source = 'manual'): AdSpend
    {
        $spend = (float) $data['spend'];
        $date = date('Y-m-d', strtotime($data['date']));
        $storeId = $data['store_id'] ?? null;

        $key = AdSpend::buildSpendKey(
            $data['channel'],
            $data['campaign_external_id'] ?? null,
            $data['sku'] ?? null,
            $date,
            $storeId,
        );

        return AdSpend::updateOrCreate(
            ['organization_id' => $organizationId, 'spend_key' => $key],
            [
                'store_id' => $storeId,
                'channel' => $data['channel'],
                'campaign_name' => $data['campaign_name'] ?? null,
                'campaign_external_id' => $data['campaign_external_id'] ?? null,
                'sku' => $data['sku'] ?? null,
                'date' => $date,
                'spend' => number_format($spend, 4, '.', ''),
                // FX for foreign-currency spend is a follow-up; base mirrors the entered amount.
                'currency' => strtoupper($data['currency'] ?? 'SAR'),
                'fx_rate_to_base' => 1,
                'spend_base' => number_format($spend, 4, '.', ''),
                'impressions' => $data['impressions'] ?? null,
                'clicks' => $data['clicks'] ?? null,
                'source' => $source,
                'created_by' => $userId,
            ]
        );
    }
}
