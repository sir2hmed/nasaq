<?php

namespace Tests\Feature;

use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_orchestrator.callback_token' => 'phase-eleven-internal-token']);
    }

    public function test_connections_are_encrypted_at_rest_and_secrets_are_redacted(): void
    {
        $user = User::factory()->create();
        $secret = 'drive-access-token-super-secret';

        $this->actingAs($user)->postJson('/api/integrations/google_drive/connect', [
            'credentials' => [
                'access_token' => $secret,
                'refresh_token' => 'drive-refresh-token-super-secret',
                'folder_id' => 'folder_123',
            ],
        ])->assertOk()
            ->assertJsonPath('data.integration.connected', true)
            ->assertJsonPath('data.integration.provider', 'google_drive')
            ->assertJsonPath('data.integration.metadata.folder_configured', true)
            ->assertJsonMissing(['access_token' => $secret])
            ->assertJsonMissing(['refresh_token' => 'drive-refresh-token-super-secret']);

        $raw = (string) DB::table('integration_connections')->value('encrypted_credentials');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertStringNotContainsString('drive-refresh-token-super-secret', $raw);
        $this->assertSame(
            $secret,
            IntegrationConnection::query()->firstOrFail()->encrypted_credentials['access_token'],
        );

        $this->getJson('/api/integrations')
            ->assertOk()
            ->assertJsonCount(3, 'data.integrations')
            ->assertJsonPath('data.integrations.0.provider', 'google_drive')
            ->assertJsonMissing(['access_token' => $secret]);
    }

    public function test_connections_are_owner_scoped_and_can_be_disconnected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owner->integrationConnections()->create([
            'provider' => 'youtube',
            'status' => 'connected',
            'encrypted_credentials' => [
                'access_token' => 'youtube-access-token',
                'privacy_status' => 'private',
            ],
            'metadata_json' => ['privacy_status' => 'private'],
            'connected_at' => now(),
        ]);

        $this->actingAs($other)->getJson('/api/integrations/youtube/status')
            ->assertOk()
            ->assertJsonPath('data.integration.connected', false);
        $this->actingAs($owner)->getJson('/api/integrations/youtube/status')
            ->assertOk()
            ->assertJsonPath('data.integration.connected', true);

        $this->actingAs($other)->deleteJson('/api/integrations/youtube')
            ->assertOk()
            ->assertJsonPath('data.connected', false);
        $this->assertDatabaseCount('integration_connections', 1);

        $this->actingAs($owner);
        $this->deleteJson('/api/integrations/youtube')
            ->assertOk()
            ->assertJsonPath('data.connected', false);
        $this->assertDatabaseCount('integration_connections', 0);
    }

    public function test_smtp_validation_and_safe_metadata(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/integrations/smtp/connect', [
            'credentials' => [
                'host' => 'smtp.example.test',
                'port' => 587,
                'username' => 'mailer@example.test',
                'password' => 'smtp-password-secret',
                'from_email' => 'noreply@example.test',
                'from_name' => 'Nasaq AI',
                'encryption' => 'tls',
            ],
        ])->assertOk()
            ->assertJsonPath('data.integration.metadata.host', 'smtp.example.test')
            ->assertJsonPath('data.integration.metadata.port', 587)
            ->assertJsonMissing(['password' => 'smtp-password-secret']);

        $this->postJson('/api/integrations/smtp/connect', [
            'credentials' => [
                'host' => 'smtp.example.test',
                'port' => 70000,
                'from_email' => 'not-an-email',
                'encryption' => 'invalid',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'credentials.port',
                'credentials.from_email',
                'credentials.encryption',
            ]);
    }

    public function test_internal_credential_endpoint_is_token_protected_and_never_cached(): void
    {
        $user = User::factory()->create();
        $user->integrationConnections()->create([
            'provider' => 'smtp',
            'status' => 'connected',
            'encrypted_credentials' => [
                'host' => 'smtp.example.test',
                'port' => 465,
                'from_email' => 'noreply@example.test',
                'encryption' => 'ssl',
                'password' => 'internal-only-secret',
            ],
            'metadata_json' => [],
            'connected_at' => now(),
        ]);

        $url = "/api/internal/users/{$user->id}/integrations/smtp";
        $this->getJson($url)->assertUnauthorized();
        $this->withHeader('X-Nasaq-Service-Token', 'wrong-token')
            ->getJson($url)->assertUnauthorized();
        $this->withHeader('X-Nasaq-Service-Token', 'phase-eleven-internal-token')
            ->getJson($url)
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertJsonPath('data.credentials.password', 'internal-only-secret');
    }

    public function test_unsupported_provider_and_missing_connection_are_safe(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/integrations/unknown/connect', [
            'credentials' => ['access_token' => 'long-enough-token'],
        ])->assertUnprocessable();
        $this->withHeader('X-Nasaq-Service-Token', 'phase-eleven-internal-token')
            ->getJson("/api/internal/users/{$user->id}/integrations/google_drive")
            ->assertNotFound()
            ->assertJsonPath('message', 'The requested integration is not connected.');
    }
}
