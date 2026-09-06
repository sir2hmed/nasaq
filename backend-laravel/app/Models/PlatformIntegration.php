<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class PlatformIntegration extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'display_name',
        'encrypted_credentials',
        'configuration_json',
        'is_enabled',
        'connection_status',
        'last_tested_at',
        'last_test_message',
        'updated_by',
    ];

    protected $casts = [
        'configuration_json' => 'array',
        'is_enabled' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    /**
     * Store credentials safely encrypted with Crypt (AES-256-CBC).
     */
    public function setEncryptedCredentials(array|string $credentials): void
    {
        $payload = is_array($credentials) ? json_encode($credentials) : $credentials;
        $this->attributes['encrypted_credentials'] = Crypt::encryptString($payload);
    }

    /**
     * Retrieve decrypted credentials for internal service consumption only.
     */
    public function getDecryptedCredentials(): array|string|null
    {
        if (empty($this->attributes['encrypted_credentials'])) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($this->attributes['encrypted_credentials']);
            $decoded = json_decode($decrypted, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $decrypted;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return a masked credential representation for safe public API exposure.
     */
    public function getMaskedCredentials(): string
    {
        $decrypted = $this->getDecryptedCredentials();

        if (empty($decrypted)) {
            return '';
        }

        if (is_array($decrypted)) {
            if (isset($decrypted['api_key'])) {
                $key = (string) $decrypted['api_key'];

                return strlen($key) > 8 ? substr($key, 0, 4).'****'.substr($key, -4) : '••••••••';
            }

            return '•••••••• (configured)';
        }

        $str = (string) $decrypted;

        return strlen($str) > 8 ? substr($str, 0, 4).'****'.substr($str, -4) : '••••••••';
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(IntegrationAuditLog::class, 'platform_integration_id');
    }
}
