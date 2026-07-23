<?php

namespace App\Services\Automation;

/** The outcome of applying one action (spec 02 §4.1). */
class ActionResult
{
    public function __construct(
        public string $actionId,
        public string $type,
        public string $status,          // applied | skipped | failed | queued
        public array $result = [],
        public ?string $error = null,
        public bool $mutated = false,   // did it change the subject?
        public bool $terminal = false,  // stop_processing style halt
        public int $durationMs = 0,
    ) {
    }

    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'type' => $this->type,
            'status' => $this->status,
            'result' => $this->result,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
        ];
    }
}
