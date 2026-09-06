<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExecutionLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'node_key' => $this->node_key,
            'level' => $this->level,
            'event_type' => $this->event_type,
            'message' => $this->message,
            'context' => $this->context_json,
            'occurred_at' => $this->occurred_at?->toISOString(),
        ];
    }
}
