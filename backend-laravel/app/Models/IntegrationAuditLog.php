<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_integration_id',
        'admin_user_id',
        'action',
        'changed_fields',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'changed_fields' => 'array',
    ];

    public function platformIntegration(): BelongsTo
    {
        return $this->belongsTo(PlatformIntegration::class, 'platform_integration_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
