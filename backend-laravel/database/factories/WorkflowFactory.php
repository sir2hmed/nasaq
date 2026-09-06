<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->sentence(3);

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'description' => fake()->sentence(),
            'graph_json' => [
                'version' => 1,
                'name' => $name,
                'description' => null,
                'nodes' => [[
                    'id' => 'researcher_01',
                    'type' => 'researcher',
                    'position' => ['x' => 120, 'y' => 180],
                    'config' => ['topic' => 'AI agents in education'],
                ]],
                'edges' => [],
            ],
            'status' => 'draft',
            'version' => 1,
            'last_run_at' => null,
        ];
    }
}
