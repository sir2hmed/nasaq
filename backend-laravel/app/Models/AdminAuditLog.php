<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_user_id',
        'target_type',
        'target_id',
        'action',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
