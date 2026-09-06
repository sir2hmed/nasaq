<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AdminAuditLogger
{
    /** @param array<string, mixed> $metadata */
    public function log(Request $request, string $action, ?string $targetType = null, ?string $targetId = null, array $metadata = []): void
    {
        /** @var User $admin */
        $admin = $request->user();

        AdminAuditLog::create([
            'admin_user_id' => $admin->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
