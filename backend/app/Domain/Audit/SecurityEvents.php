<?php

namespace App\Domain\Audit;

use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Log;

class SecurityEvents
{
    public static function log(string $type, ?string $userId = null, string $severity = 'info', array $meta = []): void
    {
        try {
            $request = app()->bound('request') ? request() : null;
            SecurityEvent::query()->create([
                'user_id' => $userId, 'type' => $type, 'severity' => $severity,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'request_id' => $request?->attributes->get('request_id'),
                'meta' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::critical('Security event write failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
