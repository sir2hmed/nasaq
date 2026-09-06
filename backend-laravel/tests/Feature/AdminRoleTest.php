<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_creates_normal_user_and_ignores_role_payload(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'admin',
            'account_status' => 'suspended',
        ]);

        $response->assertStatus(201);
        $user = User::where('email', 'john@example.test')->first();
        $this->assertNotNull($user);
        $this->assertEquals('user', $user->role);
        $this->assertEquals('active', $user->account_status);
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->isActive());
    }

    public function test_normal_user_receives_403_from_admin_endpoints(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($user)->getJson('/api/admin/overview');

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'You are not allowed to access this resource.']);
    }

    public function test_admin_user_can_access_admin_endpoints(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/overview');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'overview' => [
                        'users' => ['total', 'admins', 'active', 'suspended'],
                        'workflows' => ['total'],
                        'runs' => ['total', 'runs_today', 'success_rate', 'queued', 'running', 'success', 'failed'],
                    ],
                ],
            ]);
    }

    public function test_admin_overview_calculates_db_metrics_and_handles_empty_db_safely(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
        ]);

        // Empty database metric assertion (only 1 user exists)
        $response = $this->actingAs($admin)->getJson('/api/admin/overview');
        $response->assertStatus(200)
            ->assertJsonPath('data.overview.users.total', 1)
            ->assertJsonPath('data.overview.users.admins', 1)
            ->assertJsonPath('data.overview.workflows.total', 0)
            ->assertJsonPath('data.overview.runs.total', 0)
            ->assertJsonPath('data.overview.runs.runs_today', 0)
            ->assertJsonPath('data.overview.runs.success_rate', 0);

        // Add 1 workflow and 2 runs (1 success, 1 failed)
        $workflow = Workflow::factory()->create(['user_id' => $admin->id]);
        WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'user_id' => $admin->id,
            'status' => 'success',
        ]);
        WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'user_id' => $admin->id,
            'status' => 'failed',
        ]);

        $updatedResponse = $this->actingAs($admin)->getJson('/api/admin/overview');
        $updatedResponse->assertStatus(200)
            ->assertJsonPath('data.overview.workflows.total', 1)
            ->assertJsonPath('data.overview.runs.total', 2)
            ->assertJsonPath('data.overview.runs.runs_today', 2)
            ->assertJsonPath('data.overview.runs.success_rate', 50);
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@example.test',
            'password' => bcrypt('Password123!'),
            'role' => 'user',
            'account_status' => 'suspended',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'suspended@example.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_suspended_authenticated_user_blocked_by_active_middleware(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'account_status' => 'suspended',
        ]);

        $response = $this->actingAs($user)->getJson('/api/workflows');

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account has been suspended. Please contact an administrator.']);
    }

    public function test_cannot_demote_or_suspend_last_active_administrator(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
        ]);

        // Attempt to demote the sole admin
        $demoteResponse = $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}/role", [
            'role' => 'user',
            'confirm_password' => 'password',
        ]);
        $demoteResponse->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot demote the last active administrator.']);

        // Attempt to suspend the sole admin
        $suspendResponse = $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}/status", [
            'account_status' => 'suspended',
            'confirm_password' => 'password',
        ]);
        $suspendResponse->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot suspend the last active administrator.']);
    }

    public function test_role_change_requires_confirmation_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $user = User::factory()->create(['role' => 'user', 'account_status' => 'active']);

        $this->actingAs($admin)->patchJson("/api/admin/users/{$user->id}/role", ['role' => 'admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_password']);

        $this->actingAs($admin)->patchJson("/api/admin/users/{$user->id}/role", [
            'role' => 'admin',
            'confirm_password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'target_type' => 'user',
            'target_id' => (string) $user->id,
            'action' => 'user.role_updated',
        ]);
    }

    public function test_owner_isolation_is_preserved(): void
    {
        $user1 = User::factory()->create(['role' => 'user']);
        $user2 = User::factory()->create(['role' => 'user']);

        $workflow = Workflow::factory()->create(['user_id' => $user1->id]);

        $response = $this->actingAs($user2)->getJson("/api/workflows/{$workflow->id}");
        $response->assertStatus(403);
    }
}
