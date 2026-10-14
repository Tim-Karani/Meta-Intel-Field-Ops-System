<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /** Set true by seeders/imports to avoid noisy audit rows. */
    public static bool $muted = false;

    private const SENSITIVE = ['password', 'remember_token', 'token', 'secret', 'sha256'];

    public static function record(string $action, string $module, Model|string|null $subject = null, ?array $before = null, ?array $after = null, array $meta = []): void
    {
        if (self::$muted) {
            return;
        }

        try {
            $request = app()->bound('request') ? request() : null;
            AuditLog::query()->create([
                'actor_id' => Auth::id(),
                'action' => $action,
                'module' => $module,
                'subject_type' => $subject instanceof Model ? class_basename($subject) : (is_string($subject) ? $subject : null),
                'subject_id' => $subject instanceof Model ? (string) $subject->getKey() : null,
                'before' => $before === null ? null : self::sanitize($before),
                'after' => $after === null ? null : self::sanitize($after),
                'meta' => $meta ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'request_id' => $request?->attributes->get('request_id'),
            ]);
        } catch (\Throwable $e) {
            // Auditing must not take the business operation down, but a failure is loud in the logs.
            Log::critical('Audit write failed', ['error' => $e->getMessage(), 'action' => $action, 'module' => $module]);
        }
    }

    public static function sanitize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = match (true) {
                in_array($key, self::SENSITIVE, true) => '[redacted]',
                is_string($value) && mb_strlen($value) > 500 => mb_substr($value, 0, 500).'…',
                default => $value,
            };
        }
        return $out;
    }
}
