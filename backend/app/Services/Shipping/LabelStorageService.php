<?php

namespace App\Services\Shipping;

use App\Models\Shipment;
use App\Models\ShippingLabel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Download, store, and stream label artefacts (spec 04 §4.4 step 5). The rule: we ALWAYS keep our
 * own copy. If the carrier returns base64 we decode it; if it returns a URL we fetch it now (carrier
 * URLs expire). The carrier URL is never the only source of truth.
 */
class LabelStorageService
{
    private function disk(): string
    {
        return config('shipping.labels_disk', 'local');
    }

    /**
     * Persist a carrier label payload for a shipment.
     *
     * @param array{format?:string,content_base64?:?string,url?:?string} $label
     */
    public function store(Shipment $shipment, array $label, string $type = 'label'): ?ShippingLabel
    {
        $bytes = $this->resolveBytes($label);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $format = strtolower($label['format'] ?? 'pdf');
        $path = sprintf('org/%d/shipments/%d/%s-%s.%s',
            $shipment->organization_id, $shipment->id, $type, Str::random(12), $format);

        Storage::disk($this->disk())->put($path, $bytes);

        return ShippingLabel::create([
            'shipment_id' => $shipment->id,
            'type' => $type,
            'format' => $format,
            'disk' => $this->disk(),
            'path' => $path,
            'size_bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
        ]);
    }

    /** Stream a stored label back to the client with the right content type (spec §4.4 printing). */
    public function stream(ShippingLabel $label)
    {
        abort_unless(Storage::disk($label->disk)->exists($label->path), 404, 'Label file is missing.');

        $label->increment('printed_count');
        $label->update(['last_printed_at' => now()]);

        $contentType = match ($label->format) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'html' => 'text/html; charset=utf-8',
            default => 'application/octet-stream',
        };
        $disposition = in_array($label->format, ['pdf', 'html'], true) ? 'inline' : 'attachment';

        return response(Storage::disk($label->disk)->get($label->path), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => $disposition.'; filename="'.basename($label->path).'"',
        ]);
    }

    /** @param array{content_base64?:?string,url?:?string} $label */
    private function resolveBytes(array $label): ?string
    {
        if (! empty($label['content_base64'])) {
            return base64_decode($label['content_base64'], true) ?: null;
        }

        if (! empty($label['url'])) {
            $response = Http::timeout(20)->get($label['url']);

            return $response->successful() ? $response->body() : null;
        }

        return null;
    }
}
