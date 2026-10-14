<?php

namespace App\Domain\Notifications;

use App\Models\AppNotification;
use App\Models\Visit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationService
{
    /** @param iterable<string>|string $userIds */
    public static function notify(iterable|string $userIds, string $type, string $title, ?string $body = null, array $data = []): void
    {
        $ids = collect(is_string($userIds) ? [$userIds] : $userIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $now = now();
        AppNotification::query()->insert($ids->map(fn ($id) => [
            'id' => (string) Str::uuid(), 'user_id' => $id, 'type' => $type, 'title' => $title, 'body' => $body,
            'data' => json_encode($data), 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    /** Who should hear that a visit needs attention: the supervisor(s) and the campaign's managers. */
    public static function visitReviewers(Visit $visit): Collection
    {
        $visit->loadMissing(['assignment', 'agent.agentProfile']);

        return collect([$visit->assignment?->supervisor_id, $visit->agent?->agentProfile?->supervisor_id])
            ->merge(DB::table('campaign_users')->where('campaign_id', $visit->campaign_id)->pluck('user_id'))
            ->filter()->reject(fn ($id) => $id === $visit->agent_id)->unique()->values();
    }
}
