<?php

namespace App\Services\Warehouse;

use App\Models\ScanEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Idempotent scan recording (spec 08 §4.5).
 *
 * A warehouse phone loses signal constantly, so the app queues scans and replays them on reconnect —
 * the same scan can legitimately arrive several times. The client generates a UUID per scan; the
 * unique (organization_id, uuid) index turns any replay into a no-op that returns the ORIGINAL
 * response verbatim, so the operator sees the same answer whether the first attempt got through or
 * not. Double-counting a pick because the network flickered is the failure this prevents.
 */
class ScanRecorder
{
    /**
     * Record a scan, or replay the stored response if this uuid was already seen.
     *
     * @param array<string, mixed> $attributes
     * @param callable():array<string, mixed> $handler produces the response for a first-time scan
     * @return array{response:array<string,mixed>, duplicate:bool, event:ScanEvent}
     */
    public function record(int $organizationId, string $uuid, array $attributes, callable $handler): array
    {
        $existing = ScanEvent::where('organization_id', $organizationId)->where('uuid', $uuid)->first();
        if ($existing) {
            return ['response' => $existing->response ?? [], 'duplicate' => true, 'event' => $existing];
        }

        $response = $handler();

        try {
            $event = ScanEvent::create(array_merge([
                'uuid' => $uuid,
                'organization_id' => $organizationId,
                'received_at' => now(),
            ], $attributes, [
                'response' => $response,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Two replays raced. The row that won is authoritative — return its response.
            $event = ScanEvent::where('organization_id', $organizationId)->where('uuid', $uuid)->firstOrFail();

            return ['response' => $event->response ?? [], 'duplicate' => true, 'event' => $event];
        }

        return ['response' => $response, 'duplicate' => false, 'event' => $event];
    }
}
