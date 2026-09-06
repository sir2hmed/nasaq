<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $graph = $this->graph_json;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'version' => $this->version,
            'graph_json' => $graph,
            'node_count' => count($graph['nodes'] ?? []),
            'edge_count' => count($graph['edges'] ?? []),
            'last_run_at' => $this->last_run_at?->toISOString(),
            'latest_run_id' => $this->latestRun?->id,
            'last_run_status' => $this->latestRun?->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
