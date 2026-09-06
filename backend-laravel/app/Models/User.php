<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'preferred_locale', 'role', 'account_status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSuspended(): bool
    {
        return $this->account_status === 'suspended';
    }

    public function isActive(): bool
    {
        return $this->account_status === 'active';
    }

    public static function countActiveAdmins(): int
    {
        return static::where('role', 'admin')->where('account_status', 'active')->count();
    }

    /** @return HasMany<Workflow, $this> */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /** @return HasMany<IntegrationConnection, $this> */
    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
