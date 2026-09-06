<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentOutputResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'node_key' => $this->node_key,
            'agent_type' => $this->agent_type,
            'output_type' => $this->output_type,
            'content' => $this->content_json,
            'text_content' => $this->text_content,
            'public_url' => $this->public_url,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'file_name' => $this->metadata_json['file_name'] ?? null,
            'checksum_sha256' => $this->metadata_json['checksum_sha256'] ?? null,
            'metadata' => $this->metadata_json,
            'download_url' => $this->file_path === null
                ? null
                : route('outputs.download', ['output' => $this->id], false),
            'stream_url' => $this->file_path !== null && str_starts_with((string) $this->mime_type, 'video/')
                ? route('outputs.stream', ['output' => $this->id], false)
                : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
