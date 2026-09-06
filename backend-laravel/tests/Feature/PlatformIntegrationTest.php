<?php

namespace Tests\Feature;

use App\Models\IntegrationAuditLog;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_cannot_access_platform_integrations_apis(): void
    {
        $user = User::factory()->create(['role' => 'user', 'account_status' => 'active']);

        $response = $this->actingAs($user)->getJson('/api/admin/integrations');
        $response->assertStatus(403);

        $updateResponse = $this->actingAs($user)->putJson('/api/admin/integrations/openai', [
            'confirm_password' => 'Password123!',
            'credentials' => ['api_key' => 'sk-testkey123456789'],
        ]);
        $updateResponse->assertStatus(403);
    }

    public function test_authenticated_admin_can_update_credentials_without_reentering_a_password(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'password' => bcrypt('AdminSecret123!'),
        ]);

        $successResponse = $this->actingAs($admin)->putJson('/api/admin/integrations/openai', [
            'credentials' => ['api_key' => 'sk-testkey123456789'],
        ]);
        $successResponse->assertStatus(200)
            ->assertJsonPath('data.integration.configured', true)
            ->assertJsonPath('data.integration.provider', 'openai');

        $integration = PlatformIntegration::where('provider', 'openai')->first();
        $this->assertNotNull($integration);
        $this->assertNotEquals('sk-testkey123456789', $integration->encrypted_credentials);
        $this->assertEquals(['api_key' => 'sk-testkey123456789'], $integration->getDecryptedCredentials());
    }

    public function test_api_responses_never_expose_decrypted_credentials(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'password' => bcrypt('AdminSecret123!'),
        ]);

        $this->actingAs($admin)->putJson('/api/admin/integrations/openai', [
            'confirm_password' => 'AdminSecret123!',
            'credentials' => ['api_key' => 'sk-testsecret99998888'],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/integrations/openai');

        $response->assertStatus(200);
        $content = $response->getContent();

        // Raw key must NOT exist anywhere in JSON output
        $this->assertStringNotContainsString('sk-testsecret99998888', $content);
        $response->assertJsonPath('data.integration.masked_credentials', 'sk-t****8888');
    }

    public function test_audit_logs_created_without_exposing_secrets(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'password' => bcrypt('AdminSecret123!'),
        ]);

        $this->actingAs($admin)->putJson('/api/admin/integrations/tavily', [
            'confirm_password' => 'AdminSecret123!',
            'credentials' => 'tvly-secretkey12345',
        ]);

        $auditLog = IntegrationAuditLog::where('admin_user_id', $admin->id)->latest()->first();
        $this->assertNotNull($auditLog);
        $this->assertEquals('create', $auditLog->action);

        $logJson = json_encode($auditLog->toArray());
        $this->assertStringNotContainsString('tvly-secretkey12345', $logJson);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'target_type' => 'provider',
            'target_id' => 'tavily',
            'action' => 'provider.create',
        ]);
    }

    public function test_enable_disable_and_test_connection_endpoints(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'password' => bcrypt('AdminSecret123!'),
        ]);

        $integration = PlatformIntegration::create([
            'provider' => 'gemini',
            'display_name' => 'Google Gemini',
            'encrypted_credentials' => Crypt::encryptString('gemini-test-key'),
            'connection_status' => 'configured',
            'is_enabled' => false,
        ]);

        // Enable provider
        $enableResponse = $this->actingAs($admin)->postJson('/api/admin/integrations/gemini/enable', ['confirm_password' => 'AdminSecret123!']);
        $enableResponse->assertStatus(200)->assertJsonPath('data.integration.is_enabled', true);

        // Disable provider
        $disableResponse = $this->actingAs($admin)->postJson('/api/admin/integrations/gemini/disable', ['confirm_password' => 'AdminSecret123!']);
        $disableResponse->assertStatus(200)->assertJsonPath('data.integration.is_enabled', false);

        Http::fake(['*' => Http::response(['data' => ['success' => false, 'status' => 'authentication_failed', 'message' => 'Provider rejected the configured credentials.']], 200)]);
        // Test connection must reflect a measured, sanitized failure rather than credential presence.
        $testResponse = $this->actingAs($admin)->postJson('/api/admin/integrations/gemini/test', ['confirm_password' => 'AdminSecret123!']);
        $testResponse->assertStatus(200)->assertJsonPath('data.success', false)->assertJsonPath('data.connection_status', 'authentication_failed');
    }

    public function test_sensitive_provider_actions_use_the_active_admin_session(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        PlatformIntegration::create([
            'provider' => 'openai',
            'display_name' => 'OpenAI',
            'encrypted_credentials' => Crypt::encryptString(json_encode(['api_key' => 'test-key'])),
        ]);

        $this->actingAs($admin)->postJson('/api/admin/integrations/openai/enable')
            ->assertOk()
            ->assertJsonPath('data.integration.is_enabled', true);
    }

    public function test_destroy_credentials_removes_encrypted_credentials_and_disables_provider(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'password' => bcrypt('AdminSecret123!'),
        ]);

        $integration = PlatformIntegration::create([
            'provider' => 'smtp',
            'display_name' => 'SMTP Mail Provider',
            'encrypted_credentials' => Crypt::encryptString(json_encode(['password' => 'secret'])),
            'connection_status' => 'configured',
            'is_enabled' => true,
        ]);

        $deleteResponse = $this->actingAs($admin)->deleteJson('/api/admin/integrations/smtp/credentials', [
            'confirm_password' => 'AdminSecret123!',
        ]);

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('data.integration.configured', false)
            ->assertJsonPath('data.integration.is_enabled', false);

        $integration->refresh();
        $this->assertNull($integration->encrypted_credentials);
        $this->assertEquals('unconfigured', $integration->connection_status);
    }
}
