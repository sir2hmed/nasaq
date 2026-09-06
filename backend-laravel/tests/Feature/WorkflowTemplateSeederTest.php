<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\WorkflowGraphValidator;
use Database\Seeders\BrowserAcceptanceSeeder;
use Database\Seeders\SampleWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkflowTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_required_templates_are_seeded_as_independent_editable_workflows(): void
    {
        $seeder = app(SampleWorkflowSeeder::class);
        $seeder->run();
        User::query()->where('email', 'test@example.com')->firstOrFail()->update([
            'password' => 'temporary-drifted-password',
        ]);
        $seeder->run();

        $user = User::query()->where('email', 'test@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('NasaqDemo2026', $user->password));
        $workflows = $user->workflows()->orderBy('name')->get();
        $this->assertCount(5, $workflows);
        $this->assertEqualsCanonicalizing([
            'Research to Article',
            'Research to Video',
            'Content Publishing',
            'Campaign Distribution',
            'Full Product Demo',
        ], $workflows->pluck('name')->all());

        $validator = app(WorkflowGraphValidator::class);
        foreach ($workflows as $workflow) {
            $this->assertTrue(
                $validator->validate($workflow->graph_json)['valid'],
                "Template {$workflow->name} must be fully configured.",
            );
            $this->assertSame('draft', $workflow->status);
            $this->assertGreaterThan(0, $workflow->agentNodes()->count());
        }

        $fullTypes = collect(
            $workflows->firstWhere('name', 'Full Product Demo')->graph_json['nodes'],
        )->pluck('type')->all();
        $this->assertEqualsCanonicalizing([
            'researcher',
            'writer',
            'video',
            'approval',
            'publisher',
            'email',
            'export',
        ], $fullTypes);
    }

    public function test_browser_acceptance_fixture_is_idempotent_and_contains_a_real_private_artifact(): void
    {
        Storage::fake('local');
        $seeder = app(BrowserAcceptanceSeeder::class);
        $seeder->run();
        $seeder->run();

        $run = WorkflowRun::query()->where('status', 'success')->firstOrFail();
        $this->assertSame('Full Product Demo', $run->workflow->name);
        $this->assertCount(9, $run->logs);
        $this->assertCount(7, $run->outputs);
        $artifact = $run->outputs()->where('output_type', 'artifact')->firstOrFail();
        $this->assertSame('text/markdown; charset=utf-8', $artifact->mime_type);
        Storage::disk('local')->assertExists($artifact->file_path);
    }
}
