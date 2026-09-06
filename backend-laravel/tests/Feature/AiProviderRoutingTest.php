<?php

namespace Tests\Feature;

use App\Models\AiAgentProviderConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_read_or_change_provider_routing(): void
    {
        $user = User::factory()->create(['role' => 'user', 'account_status' => 'active']);
        $this->actingAs($user)->getJson('/api/admin/provider-routing')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/provider-routing/configurations', [])->assertForbidden();
    }

    public function test_configuration_key_is_encrypted_and_never_returned(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active', 'password' => bcrypt('AdminSecret123!')]);
        $response = $this->actingAs($admin)->postJson('/api/admin/provider-routing/configurations', [
            'category_key' => 'writer', 'provider_key' => 'openai', 'model_key' => 'gpt-4o',
            'api_key' => 'sk-routing-secret-123456',
        ])->assertCreated();
        $configuration = AiAgentProviderConfiguration::firstOrFail();
        $this->assertStringNotContainsString('sk-routing-secret-123456', $configuration->encrypted_credentials);
        $this->assertStringNotContainsString('sk-routing-secret-123456', $response->getContent());
        $response->assertJsonPath('data.configuration.masked_credentials', 'sk-r****3456');
    }

    public function test_admin_can_save_a_gemini_configuration_for_its_selected_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);

        $response = $this->actingAs($admin)->postJson('/api/admin/provider-routing/configurations', [
            'category_key' => 'writer',
            'provider_key' => 'gemini',
            'model_key' => 'gemini-3.7-flash',
            'api_key' => 'AIza-test-secret-123456',
        ])->assertCreated();

        $configuration = AiAgentProviderConfiguration::firstOrFail();
        $this->assertSame('draft', $configuration->status);
        $this->assertSame('writer', $configuration->category->category_key);
        $this->assertStringNotContainsString('AIza-test-secret-123456', $response->getContent());
        $response->assertJsonPath('data.configuration.category_key', 'writer');
        $response->assertJsonPath('data.configuration.masked_credentials', 'AIza****3456');
    }

    public function test_unverified_configuration_cannot_become_primary(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active', 'password' => bcrypt('AdminSecret123!')]);
        $this->actingAs($admin)->postJson('/api/admin/provider-routing/configurations', [
            'category_key' => 'writer', 'provider_key' => 'openai', 'model_key' => 'gpt-4o', 'api_key' => 'sk-routing-secret-123456',
        ])->assertCreated();
        $configuration = AiAgentProviderConfiguration::firstOrFail();
        $this->actingAs($admin)->postJson("/api/admin/provider-routing/configurations/{$configuration->id}/activate", ['category_key' => 'writer'])->assertStatus(422);
    }

    public function test_verified_configuration_can_be_activated_as_the_only_primary(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active', 'password' => bcrypt('AdminSecret123!')]);
        $this->actingAs($admin)->postJson('/api/admin/provider-routing/configurations', [
            'category_key' => 'writer', 'provider_key' => 'openai', 'model_key' => 'gpt-4o', 'api_key' => 'sk-routing-secret-123456',
        ])->assertCreated();
        $configuration = AiAgentProviderConfiguration::firstOrFail();
        Http::fake(['*' => Http::response(['data' => ['success' => true, 'status' => 'reachable', 'message' => 'Connection verified.']], 200)]);
        $this->actingAs($admin)->postJson("/api/admin/provider-routing/configurations/{$configuration->id}/test")->assertOk()->assertJsonPath('data.configuration.status', 'verified');
        $this->actingAs($admin)->postJson("/api/admin/provider-routing/configurations/{$configuration->id}/activate", ['category_key' => 'writer'])->assertOk()->assertJsonPath('data.route.is_primary', true);
    }

    public function test_configuration_cannot_be_activated_for_a_different_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $this->actingAs($admin)->postJson('/api/admin/provider-routing/configurations', [
            'category_key' => 'writer', 'provider_key' => 'gemini', 'model_key' => 'gemini-3.7-flash', 'api_key' => 'AIza-test-secret-123456',
        ])->assertCreated();
        $configuration = AiAgentProviderConfiguration::firstOrFail();
        Http::fake(['*' => Http::response(['data' => ['success' => true, 'status' => 'reachable', 'message' => 'Connection verified.']], 200)]);
        $this->actingAs($admin)->postJson("/api/admin/provider-routing/configurations/{$configuration->id}/test")->assertOk();

        $this->actingAs($admin)->postJson("/api/admin/provider-routing/configurations/{$configuration->id}/activate", ['category_key' => 'email'])
            ->assertStatus(422);
    }
}
