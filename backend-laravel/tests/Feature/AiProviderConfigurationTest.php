<?php

namespace Tests\Feature;

use App\Models\AiProviderConfiguration;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class AiProviderConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_cannot_access_provider_config_apis(): void
    {
        $user = User::factory()->create(['role' => 'user', 'account_status' => 'active']);

        $response = $this->actingAs($user)->getJson('/api/admin/provider-config');
        $response->assertStatus(403);

        $updateResponse = $this->actingAs($user)->putJson('/api/admin/provider-config', [
            'platform_default' => 'gemini',
        ]);
        $updateResponse->assertStatus(403);
    }

    public function test_admin_can_retrieve_and_update_ai_provider_config(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);

        $getResponse = $this->actingAs($admin)->getJson('/api/admin/provider-config');
        $getResponse->assertStatus(200)
            ->assertJsonPath('data.platform_default_provider', null)
            ->assertJsonPath('data.providers.openai.configured', false);

        $integration = PlatformIntegration::create([
            'provider' => 'gemini', 'display_name' => 'Google Gemini',
            'encrypted_credentials' => Crypt::encryptString(json_encode(['api_key' => 'test-gemini-key'])),
            'is_enabled' => true, 'connection_status' => 'reachable',
        ]);

        $updateResponse = $this->actingAs($admin)->putJson('/api/admin/provider-config', [
            'platform_default' => 'gemini',
            'gemini_default_model' => 'gemini-1.5-pro',
            'confirm_password' => 'password',
        ]);
        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.platform_default_provider', 'gemini')
            ->assertJsonPath('data.providers.gemini.default_model', 'gemini-1.5-pro');

        $this->assertTrue(AiProviderConfiguration::where('provider', 'gemini')->first()->is_default);
        $this->assertFalse(AiProviderConfiguration::where('provider', 'openai')->first()->is_default);
    }

    public function test_only_one_provider_can_be_platform_default(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);

        $openai = PlatformIntegration::create(['provider' => 'openai', 'display_name' => 'OpenAI', 'encrypted_credentials' => Crypt::encryptString(json_encode(['api_key' => 'test-openai-key'])), 'is_enabled' => true, 'connection_status' => 'reachable']);
        $gemini = PlatformIntegration::create(['provider' => 'gemini', 'display_name' => 'Gemini', 'encrypted_credentials' => Crypt::encryptString(json_encode(['api_key' => 'test-gemini-key'])), 'is_enabled' => true, 'connection_status' => 'reachable']);
        AiProviderConfiguration::create(['provider' => 'openai', 'platform_integration_id' => $openai->id, 'default_model' => 'gpt-4o', 'is_enabled' => true]);
        AiProviderConfiguration::create(['provider' => 'gemini', 'platform_integration_id' => $gemini->id, 'default_model' => 'gemini-1.5-flash', 'is_enabled' => true]);

        $this->actingAs($admin)->putJson('/api/admin/provider-config', ['platform_default' => 'gemini', 'confirm_password' => 'password'])->assertOk();
        $this->assertEquals(1, AiProviderConfiguration::where('is_default', true)->count());
        $this->assertEquals('gemini', AiProviderConfiguration::where('is_default', true)->first()->provider);

        $this->actingAs($admin)->putJson('/api/admin/provider-config', ['platform_default' => 'openai', 'confirm_password' => 'password'])->assertOk();
        $this->assertEquals(1, AiProviderConfiguration::where('is_default', true)->count());
        $this->assertEquals('openai', AiProviderConfiguration::where('is_default', true)->first()->provider);
    }
}
