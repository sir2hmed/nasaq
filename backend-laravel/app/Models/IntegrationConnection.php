<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'provider',
    'status',
    'encrypted_credentials',
    'metadata_json',
    'connected_at',
    'expires_at',
])]
#[Hidden(['encrypted_credentials'])]
class IntegrationConnection extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'encrypted_credentials' => 'encrypted:array',
            'metadata_json' => 'array',
            'connected_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
