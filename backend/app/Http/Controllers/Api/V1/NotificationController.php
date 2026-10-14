<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AppNotification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A user only ever sees their own notifications (scope is by user_id). */
class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $q = AppNotification::query()->where('user_id', $request->user()->getKey());
        if ($request->boolean('unread')) {
            $q->whereNull('read_at');
        }
        $page = $q->orderByDesc('created_at')->orderBy('id')->paginate($this->perPage($request));

        return ApiResponse::paginated($page, $page->items(), ['unread' => AppNotification::query()->where('user_id', $request->user()->getKey())->whereNull('read_at')->count()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->ok(['unread' => AppNotification::query()->where('user_id', $request->user()->getKey())->whereNull('read_at')->count()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $this->assertUuid($id);
        $n = AppNotification::query()->where('user_id', $request->user()->getKey())->findOrFail($id);
        $n->read_at ?? $n->update(['read_at' => now()]);

        return $this->ok($n->refresh());
    }

    public function readAll(Request $request): JsonResponse
    {
        $count = AppNotification::query()->where('user_id', $request->user()->getKey())->whereNull('read_at')->update(['read_at' => now()]);

        return $this->ok(['marked' => $count], 'All notifications marked as read.');
    }
}
