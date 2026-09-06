<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationConnectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $credentials = $this->encrypted_credentials ?? [];

        return [
            'provider' => $this->provider,
            'status' => $this->status,
            'connected' => $this->status === 'connected',
            'configured_fields' => array_values(array_keys(array_filter(
                $credentials,
                fn (mixed $value): bool => $value !== null && $value !== '',
            ))),
            'metadata' => $this->metadata_json ?? [],
            'connected_at' => $this->connected_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
