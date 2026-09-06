<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_uses_the_standard_envelope(): void
    {
        config(['nasaq.health.check_dependencies' => false]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'laravel')
            ->assertJsonPath('data.mode', 'demo')
            ->assertJsonPath('data.checks.database.ready', true)
            ->assertJsonPath('data.checks.redis.ready', true)
            ->assertJsonStructure(['data', 'message', 'errors']);
    }

    public function test_configured_frontend_origin_is_allowed_by_cors(): void
    {
        config(['nasaq.health.check_dependencies' => false]);

        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/health')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_api_responses_return_a_safe_correlation_identifier(): void
    {
        config(['nasaq.health.check_dependencies' => false]);

        $this->withHeader('X-Correlation-ID', 'browser-request-1234')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('X-Correlation-ID', 'browser-request-1234');

        $generated = $this->withHeader('X-Correlation-ID', "unsafe\r\nvalue")
            ->getJson('/api/health')
            ->assertOk()
            ->headers->get('X-Correlation-ID');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $generated);
    }
}
