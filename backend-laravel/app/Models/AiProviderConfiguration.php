<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'platform_integration_id',
        'default_model',
        'is_enabled',
        'is_default',
        'connection_status',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function platformIntegration(): BelongsTo
    {
        return $this->belongsTo(PlatformIntegration::class, 'platform_integration_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Mark specified provider as the platform default, clearing default flag on all others.
     */
    public static function setPlatformDefault(string $defaultProvider, ?int $adminId = null): self
    {
        static::query()->update(['is_default' => false]);

        $config = static::firstOrCreate(
            ['provider' => $defaultProvider],
            [
                'default_model' => $defaultProvider === 'gemini' ? 'gemini-1.5-flash' : 'gpt-4o',
                'is_enabled' => true,
                'connection_status' => 'configured',
            ]
        );

        $config->update([
            'is_default' => true,
            'is_enabled' => true,
            'updated_by' => $adminId,
        ]);

        return $config;
    }
}
