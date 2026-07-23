<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Manifest {{ $manifest->reference }}</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, "Segoe UI", Tahoma, Arial, sans-serif; color: #111; margin: 0; padding: 24px; }
    .sheet { max-width: 820px; margin: 0 auto; }
    .row { display: flex; justify-content: space-between; align-items: flex-start; }
    h1 { font-size: 20px; margin: 0; }
    .muted { color: #666; font-size: 12px; }
    .ref { font-family: monospace; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #eee; font-size: 12px; }
    th { text-transform: uppercase; font-size: 10px; letter-spacing: .04em; color: #666; }
    .sign { margin-top: 40px; display: flex; justify-content: space-between; }
    .sign div { border-top: 1px solid #999; padding-top: 6px; width: 45%; font-size: 12px; color: #666; }
</style>
</head>
<body>
<div class="sheet">
    <div class="row">
        <div>
            <h1>Handover Manifest · مانيفست التسليم</h1>
            <div class="muted">{{ $carrierLabel }} ({{ strtoupper($manifest->carrier_code) }})</div>
        </div>
        <div style="text-align:right;">
            <div class="ref">{{ $manifest->reference }}</div>
            <div class="muted">{{ $manifest->manifest_date?->format('Y-m-d') }}</div>
            <div class="muted">{{ $manifest->shipment_count }} shipments · {{ number_format((float) $manifest->total_weight_kg, 2) }} kg</div>
        </div>
    </div>

    <table>
        <thead>
            <tr><th>#</th><th>Reference</th><th>AWB</th><th>Weight</th><th>COD</th></tr>
        </thead>
        <tbody>
            @foreach($shipments as $i => $s)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="ref">{{ $s->reference }}</td>
                <td class="ref">{{ $s->tracking_number ?? '—' }}</td>
                <td>{{ number_format((float) $s->total_weight_kg, 2) }} kg</td>
                <td>{{ $s->is_cod ? number_format((float) $s->cod_amount, 2).' '.($s->cod_currency ?? $s->currency) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="sign">
        <div>Merchant signature · توقيع التاجر</div>
        <div>Driver signature · توقيع المندوب</div>
    </div>
</div>
</body>
</html>
