<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use Database\Seeders\SampleWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_list_reload_update_and_delete_a_workflow(): void
    {
        $user = User::factory()->create();

        $created = $this->actingAs($user)->postJson('/api/workflows', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.workflow.name', 'Research to Article')
            ->assertJsonPath('data.workflow.node_count', 3)
            ->assertJsonPath('data.workflow.edge_count', 2)
            ->assertJsonPath('errors', null)
            ->json('data.workflow');

        $workflowId = $created['id'];
        $this->assertDatabaseHas('workflows', [
            'id' => $workflowId,
            'user_id' => $user->id,
            'name' => 'Research to Article',
        ]);
        $this->assertDatabaseCount('agent_nodes', 3);
        $this->assertDatabaseCount('workflow_edges', 2);

        $this->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonPath('data.workflows.0.id', $workflowId)
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/workflows/$workflowId")
            ->assertOk()
            ->assertJsonPath('data.workflow.graph_json.nodes.1.id', 'writer_01')
            ->assertJsonPath('data.workflow.graph_json.edges.1.target', 'export_01');

        $updatedPayload = $this->payload();
        $updatedPayload['name'] = 'Updated research workflow';
        $updatedPayload['description'] = 'Updated description';
        $updatedPayload['graph_json']['nodes'][0]['position']['x'] = 222.5;

        $this->putJson("/api/workflows/$workflowId", $updatedPayload)
            ->assertOk()
            ->assertJsonPath('data.workflow.name', 'Updated research workflow')
            ->assertJsonPath('data.workflow.graph_json.name', 'Updated research workflow')
            ->assertJsonPath('data.workflow.graph_json.nodes.0.position.x', 222.5);

        $this->assertDatabaseHas('agent_nodes', [
            'workflow_id' => $workflowId,
            'node_key' => 'researcher_01',
            'position_x' => 222.5,
        ]);

        $this->deleteJson("/api/workflows/$workflowId")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('workflows', ['id' => $workflowId]);
        $this->getJson("/api/workflows/$workflowId")->assertNotFound();
    }

    public function test_user_can_duplicate_an_owned_workflow_with_an_independent_graph(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create([
            'name' => 'Source workflow',
            'graph_json' => $this->payload()['graph_json'],
        ]);

        $copy = $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.workflow.name', 'Source workflow (Copy)')
            ->assertJsonPath('data.workflow.status', 'draft')
            ->assertJsonPath('data.workflow.node_count', 3)
            ->json('data.workflow');

        $this->assertNotSame($workflow->id, $copy['id']);
        $this->assertDatabaseHas('workflows', [
            'id' => $copy['id'],
            'user_id' => $user->id,
            'name' => 'Source workflow (Copy)',
        ]);
        $this->assertDatabaseCount('agent_nodes', 3);
        $this->assertDatabaseCount('workflow_edges', 2);
    }

    public function test_workflow_ownership_is_enforced_on_every_resource_action(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $workflow = Workflow::factory()->for($owner)->create();

        $this->actingAs($intruder)->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson("/api/workflows/{$workflow->id}")->assertForbidden();
        $this->putJson("/api/workflows/{$workflow->id}", $this->payload())->assertForbidden();
        $this->postJson("/api/workflows/{$workflow->id}/duplicate")->assertForbidden();
        $this->deleteJson("/api/workflows/{$workflow->id}")->assertForbidden();

        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'deleted_at' => null]);
    }

    public function test_graph_validation_rejects_unknown_edge_nodes_with_standard_errors(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload();
        $payload['graph_json']['edges'][0]['target'] = 'missing_01';

        $this->actingAs($user)->postJson('/api/workflows', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'The submitted data is invalid.')
            ->assertJsonValidationErrors(['graph_json']);

        $this->assertDatabaseCount('workflows', 0);
    }

    public function test_graph_validation_rejects_cycles(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload();
        $payload['graph_json']['edges'][] = [
            'id' => 'edge_export_researcher',
            'source' => 'export_01',
            'target' => 'researcher_01',
        ];

        $this->actingAs($user)->postJson('/api/workflows', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['graph_json']);

        $this->assertDatabaseCount('workflows', 0);
    }

    public function test_validate_endpoint_reports_required_configuration_warnings(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload();
        $payload['graph_json']['nodes'][0]['config']['topic'] = '';
        $workflow = Workflow::factory()->for($user)->create([
            'graph_json' => $payload['graph_json'],
        ]);

        $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/validate")
            ->assertOk()
            ->assertJsonPath('data.validation.valid', false)
            ->assertJsonPath('data.validation.errors', [])
            ->assertJsonPath('data.validation.warnings.0.code', 'required_config')
            ->assertJsonPath('data.validation.warnings.0.node_id', 'researcher_01')
            ->assertJsonPath('data.validation.warnings.0.field', 'topic');
    }

    public function test_validate_endpoint_reports_invalid_agent_configuration_values(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload();
        $payload['graph_json']['nodes'][0]['config']['source_count'] = 0;
        $payload['graph_json']['nodes'][0]['config']['language'] = 'fr';
        $workflow = Workflow::factory()->for($user)->create([
            'graph_json' => $payload['graph_json'],
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/validate")
            ->assertOk()
            ->assertJsonPath('data.validation.valid', false);

        $issues = collect($response->json('data.validation.warnings'));
        $this->assertTrue($issues->contains(fn (array $issue): bool => $issue['code'] === 'invalid_config'
            && $issue['field'] === 'source_count'));
        $this->assertTrue($issues->contains(fn (array $issue): bool => $issue['code'] === 'invalid_config'
            && $issue['field'] === 'language'));
    }

    public function test_guests_cannot_access_workflows(): void
    {
        $this->getJson('/api/workflows')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Authentication is required.');
    }

    public function test_sample_workflow_seeder_creates_all_template_graphs_and_reporting_rows(): void
    {
        $this->seed(SampleWorkflowSeeder::class);

        $this->assertDatabaseHas('workflows', [
            'name' => 'Research to Article',
            'status' => 'draft',
        ]);
        $this->assertDatabaseCount('workflows', 5);
        $this->assertDatabaseCount('agent_nodes', 22);
        $this->assertDatabaseCount('workflow_edges', 17);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => 'Research to Article',
            'description' => 'Core workflow graph',
            'status' => 'draft',
            'graph_json' => [
                'version' => 1,
                'name' => 'Research to Article',
                'description' => 'Core workflow graph',
                'nodes' => [
                    [
                        'id' => 'researcher_01',
                        'type' => 'researcher',
                        'position' => ['x' => 120, 'y' => 180],
                        'config' => ['topic' => 'AI agents in education'],
                    ],
                    [
                        'id' => 'writer_01',
                        'type' => 'writer',
                        'position' => ['x' => 460, 'y' => 180],
                        'config' => ['style' => 'professional'],
                    ],
                    [
                        'id' => 'export_01',
                        'type' => 'export',
                        'position' => ['x' => 800, 'y' => 180],
                        'config' => ['formats' => ['markdown', 'pdf', 'docx']],
                    ],
                ],
                'edges' => [
                    [
                        'id' => 'edge_researcher_writer',
                        'source' => 'researcher_01',
                        'target' => 'writer_01',
                    ],
                    [
                        'id' => 'edge_writer_export',
                        'source' => 'writer_01',
                        'target' => 'export_01',
                    ],
                ],
            ],
        ];
    }
}
