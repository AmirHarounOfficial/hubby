<div class="sheet">
    <div class="row">
        <div>
            @if($logoUrl)<img src="{{ $logoUrl }}" alt="" style="max-height:44px;margin-bottom:6px;">@endif
            <h1>{{ $merchantName }}</h1>
            <div class="muted">Packing Slip · قائمة التعبئة</div>
        </div>
        <div style="text-align:right;">
            @if($orderNumber)<div class="muted">Order / الطلب</div><div class="ref">{{ $orderNumber }}</div>@endif
            <div class="muted" style="margin-top:6px;">Shipment / الشحنة</div>
            <div class="ref">{{ $shipment->reference }}</div>
            @if($shipment->tracking_number)
                <div class="muted" style="margin-top:6px;">{{ strtoupper($shipment->carrier_code ?? '') }} AWB</div>
                <div class="ref">{{ $shipment->tracking_number }}</div>
            @endif
        </div>
    </div>

    @if($to)
    <div class="box" style="margin-top:16px;">
        <div class="muted">Ship to · المستلم — {{ ($packageCount > 1) ? "1 of {$packageCount}" : '1 of 1' }}</div>
        <div style="font-weight:600;margin-top:4px;">{{ $to->name }}</div>
        @if($to->line1)<div>{{ $to->line1 }}</div>@endif
        @if($to->district || $to->city)<div>{{ trim(implode(', ', array_filter([$to->district, $to->city]))) }}</div>@endif
        @if($to->state || $to->country_code)<div>{{ trim(implode(', ', array_filter([$to->state, $to->country_code]))) }}</div>@endif
        @if($to->phone)<div class="muted">{{ $to->phone }}</div>@endif
    </div>
    @endif

    <table>
        <thead><tr><th>SKU</th><th>Item · الصنف</th><th class="qty">Qty · الكمية</th></tr></thead>
        <tbody>
            @foreach($items as $line)
            <tr><td class="ref">{{ $line['sku'] }}</td><td>{{ $line['name'] }}</td><td class="qty">{{ $line['quantity'] }}</td></tr>
            @endforeach
        </tbody>
    </table>

    @if($isCod)
    <div class="cod">
        Collect on delivery · مطلوب تحصيل عند الاستلام:
        {{ number_format((float) $shipment->cod_amount, 2) }} {{ $shipment->cod_currency ?? $shipment->currency }}
    </div>
    @endif

    <div class="barcode">{!! $barcodeSvg !!}<div class="ref">{{ $shipment->reference }}</div></div>

    @if($footer)<div class="footer">{{ $footer }}</div>@endif
</div>
