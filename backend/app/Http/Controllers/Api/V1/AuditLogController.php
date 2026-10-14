<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only. The audit trail is append-only (also enforced by a database trigger). */
class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);
        $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']]);

        $q = AccessScope::scope(AuditLog::query(), $request->user())->with('actor:id,name');
        foreach (['module', 'action', 'actor_id', 'subject_type', 'subject_id'] as $f) {
            if (($v = $this->filterValue($request, $f, $f === 'actor_id')) !== null) {
                $q->where($f, $v);
            }
        }
        if ($from = $request->query('date_from')) {
            $q->where('created_at', '>=', $from.' 00:00:00');
        }
        if ($to = $request->query('date_to')) {
            $q->where('created_at', '<=', $to.' 23:59:59');
        }
        if (($term = $this->searchTerm($request)) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn ($w) => $w->where('action', 'ilike', $like)->orWhere('module', 'ilike', $like)->orWhere('subject_id', 'ilike', $like));
        }
        $page = $q->orderByDesc('id')->paginate($this->perPage($request));

        return ApiResponse::paginated($page, $page->items());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);
        abort_unless(ctype_digit($id), 404);

        return $this->ok(AccessScope::scope(AuditLog::query(), $request->user())->with('actor:id,name')->findOrFail((int) $id));
    }

    /** Failed logins, lockouts, token abuse, photo re-use, ... (system administrators only). */
    public function securityEvents(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('system.manage'), 403);
        $q = SecurityEvent::query();
        foreach (['type', 'severity', 'user_id'] as $f) {
            if (($v = $this->filterValue($request, $f, $f === 'user_id')) !== null) {
                $q->where($f, $v);
            }
        }
        $page = $q->orderByDesc('id')->paginate($this->perPage($request));

        return ApiResponse::paginated($page, $page->items());
    }
}
