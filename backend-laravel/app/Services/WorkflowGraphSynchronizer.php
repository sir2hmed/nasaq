<?php

namespace App\Services;

use App\Models\Workflow;

class WorkflowGraphSynchronizer
{
    public function sync(Workflow $workflow): void
    {
        $graph = $workflow->graph_json;

        $workflow->edges()->delete();
        $workflow->agentNodes()->delete();

        foreach ($graph['nodes'] as $node) {
            $workflow->agentNodes()->create([
                'node_key' => $node['id'],
                'agent_type' => $node['type'],
                'configuration_json' => $node['config'],
                'position_x' => $node['position']['x'],
                'position_y' => $node['position']['y'],
            ]);
        }

        foreach ($graph['edges'] as $edge) {
            $workflow->edges()->create([
                'edge_key' => $edge['id'],
                'source_node_key' => $edge['source'],
                'target_node_key' => $edge['target'],
            ]);
        }
    }
}
