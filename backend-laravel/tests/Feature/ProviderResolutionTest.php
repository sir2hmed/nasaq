<?php

namespace Tests\Feature;

use App\Models\AiAgentProviderConfiguration;
use App\Models\AiProviderCategory;
use App\Models\AiProviderCategoryRoute;
use App\Models\AiProviderDefinition;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_orchestrator.callback_token' => 'provider-resolution-token']);
    }

    public function test_resolution_is_service_token_only_and_returns_minimum_execution_payload(): void
    {
        $run = $this->realRun('openai', true);
        $url = "/api/internal/runs/{$run->id}/provider-resolution";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertUnauthorized();
        $response = $this->withHeader('X-Nasaq-Service-Token', 'provider-resolution-token')->getJson($url);

        $response->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.model', 'gpt-4o')
            ->assertJsonPath('data.credentials.api_key', 'test-provider-key');
        $this->assertArrayNotHasKey('encrypted_credentials', $response->json('data'));
    }

    public function test_disabled_or_unconfigured_provider_blocks_real_resolution(): void
    {
        $run = $this->realRun('gemini', false);
        $this->withHeader('X-Nasaq-Service-Token', 'provider-resolution-token')
            ->getJson("/api/internal/runs/{$run->id}/provider-resolution")
            ->assertStatus(422)
            ->assertJsonPath('data.state', 'unconfigured');
    }

    private function realRun(string $provider, bool $enabled): WorkflowRun
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create();
        if ($enabled) {
            $definition = AiProviderDefinition::where('provider_key', $provider)->firstOrFail();
            $category = AiProviderCategory::where('category_key', 'writer')->firstOrFail();
            $configuration = new AiAgentProviderConfiguration([
                'ai_provider_definition_id' => $definition->id,
                'ai_provider_category_id' => $category->id,
                'model_key' => $provider === 'gemini' ? 'gemini-3.7-flash' : 'gpt-4o',
                'status' => 'verified',
            ]);
            $configuration->setCredentials('test-provider-key');
            $configuration->save();
            AiProviderCategoryRoute::create([
                'ai_provider_category_id' => $category->id,
                'ai_agent_provider_configuration_id' => $configuration->id,
                'is_primary' => true,
                'is_active' => true,
            ]);
        }

        return WorkflowRun::factory()->for($workflow)->for($user)->create(['demo_mode' => false]);
    }
}
