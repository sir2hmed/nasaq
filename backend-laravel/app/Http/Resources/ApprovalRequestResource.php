<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'node_key' => $this->node_key,
            'status' => $this->status,
            'comment' => $this->comment,
            'decided_at' => $this->decided_at?->toISOString(),
            'preview_output' => $this->whenLoaded(
                'previewOutput',
                fn () => $this->previewOutput
                    ? AgentOutputResource::make($this->previewOutput)->resolve($request)
                    : null,
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
