<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Reports\AgentTrackingService;
use App\Domain\Reports\ExecutiveService;
use App\Domain\Reports\ScoringService;
use App\Models\Campaign;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Executive summary + live field-agent overview (read only). */
class ExecutiveController extends ApiController
{
    public function summary(Request $request, ExecutiveService $service): JsonResponse
    {
        $f = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'client_id' => ['nullable', 'uuid'], 'campaign_id' => ['nullable', 'uuid'],
            'period' => ['nullable', Rule::in(['week', 'month'])], 'channel' => ['nullable', 'string', 'max:30'],
        ]);
        $user = $request->user();
        if (!empty($f['client_id'])) {
            AccessScope::scope(Client::query(), $user)->findOrFail($f['client_id']);
        }
        if (!empty($f['campaign_id'])) {
            AccessScope::scope(Campaign::query(), $user)->findOrFail($f['campaign_id']);
        }

        return $this->ok($service->summary($user, $f));
    }

    public function agents(Request $request, AgentTrackingService $service): JsonResponse
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'seen_after' => ['nullable', 'date_format:Y-m-d'], 'team_leader_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['in_app', 'offline', 'not_started'])],
            'client_id' => ['nullable', 'uuid'], 'campaign_id' => ['nullable', 'uuid'],
        ]);

        return $this->ok($service->list($request->user(), $f));
    }

    public function agent(Request $request, string $id, AgentTrackingService $service): JsonResponse
    {
        $this->assertUuid($id);
        $f = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return $this->ok($service->detail($request->user(), $id, $f['date'] ?? now()->toDateString()));
    }

    public function scores(Request $request, AgentTrackingService $tracking, ScoringService $scoring): JsonResponse
    {
        $f = $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']]);
        $from = $f['date_from'] ?? now()->subDays(29)->toDateString();
        $to = $f['date_to'] ?? now()->toDateString();
        $agents = $tracking->agentIdsQuery($request->user())->orderBy('users.name')->limit(500)->get(['users.id', 'users.name']);
        $scores = $scoring->forAgents($agents->pluck('id')->all(), $from, $to);
        $rows = $agents->map(fn ($a) => ['id' => $a->id, 'name' => $a->name] + $scores[$a->id])->sortByDesc(fn ($r) => $r['score'] ?? -1)->values();

        return $this->ok($rows, 'Agent scores.', ['from' => $from, 'to' => $to, 'weights' => config('field.scoring.weights')]);
    }
}
