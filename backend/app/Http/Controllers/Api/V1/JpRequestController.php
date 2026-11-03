<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Assignments\JourneyPlanService;
use App\Domain\Audit\AuditService;
use App\Domain\Notifications\NotificationService;
use App\Models\JourneyPlan;
use App\Models\JpChangeRequest;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Journey-plan change requests: the merchandiser asks (with GPS and an optional photo as proof), the supervisor / team leader approves
 * or rejects in the dashboard, and an approval updates the route straight away. Everything is audited.
 */
class JpRequestController extends ApiController
{
    private function scoped(Request $request): Builder
    {
        $user = $request->user();
        $q = JpChangeRequest::query()->with(['agent:id,name', 'location:id,name,town', 'plan:id,campaign_id,name', 'plan.campaign:id,name', 'decider:id,name']);

        return match ($user->scopeType()) {
            'all' => $q,
            'own' => $q->where('jp_change_requests.agent_id', $user->getKey()),
            'team' => $q->where(fn ($w) => $w->whereIn('jp_change_requests.agent_id', AccessScope::supervisedAgentIds($user))->orWhere('jp_change_requests.agent_id', $user->getKey())),
            'campaigns' => $q->whereIn('jp_change_requests.journey_plan_id', JourneyPlan::query()->whereIn('campaign_id', AccessScope::campaignIds($user))->select('id')),
            default => $q->whereRaw('1 = 0'),
        };
    }

    private function present(JpChangeRequest $r): array
    {
        return [
            'id' => $r->id, 'type' => $r->type, 'status' => $r->status, 'weekday' => $r->weekday, 'to_weekday' => $r->to_weekday, 'reason' => $r->reason,
            'agent' => $r->agent?->only(['id', 'name']), 'location' => $r->location?->only(['id', 'name', 'town']), 'campaign' => $r->plan?->campaign?->only(['id', 'name']),
            'latitude' => $r->latitude, 'longitude' => $r->longitude, 'has_proof' => (bool) $r->proof_path, 'decided_by' => $r->decider?->name, 'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note, 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]]);
        $rows = $this->scoped($request)->when($request->query('status'), fn ($q, $s) => $q->where('jp_change_requests.status', $s))
            ->when(\App\Domain\Access\ProjectFocus::campaignIds($request->query()), fn ($q, $f) => $q->whereIn('jp_change_requests.journey_plan_id', JourneyPlan::query()->whereIn('campaign_id', $f)->select('id')))
            ->orderByRaw("case when jp_change_requests.status = 'pending' then 0 else 1 end")->orderByDesc('jp_change_requests.created_at')->limit(200)->get();

        return $this->ok($rows->map(fn ($r) => $this->present($r))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate([
            'client_uuid' => ['nullable', 'uuid'], 'journey_plan_id' => ['required', 'uuid'], 'type' => ['required', Rule::in(['add', 'remove', 'move'])],
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')], 'weekday' => ['required', 'integer', 'between:1,7'], 'to_weekday' => ['nullable', 'integer', 'between:1,7'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'], 'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'], 'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'], 'photo' => ['nullable', 'image', 'max:8192'],
        ]);
        if (!empty($d['client_uuid']) && ($dup = JpChangeRequest::query()->where('agent_id', $user->getKey())->where('client_uuid', $d['client_uuid'])->first())) {
            return $this->ok($this->present($dup->load(['agent:id,name', 'location:id,name,town', 'plan.campaign:id,name'])), 'Already received.');
        }
        $plan = JourneyPlan::query()->where('agent_id', $user->getKey())->findOrFail($d['journey_plan_id']);   // you can only ask to change your own plan
        if ($plan->status !== 'active') {
            throw DomainRuleException::conflict('This journey plan is not active.', 'plan_inactive');
        }
        $target = $d['type'] === 'remove' ? $d['weekday'] : ($d['to_weekday'] ?? ($d['type'] === 'add' ? $d['weekday'] : null));
        if ($target === null || ($d['type'] === 'move' && $target === (int) $d['weekday'])) {
            throw new DomainRuleException('Choose the new weekday.', 'invalid_request', 422);
        }
        $inPlan = $plan->stops()->where('location_id', $d['location_id'])->where('weekday', $d['weekday'])->exists();
        if (in_array($d['type'], ['remove', 'move'], true) && !$inPlan) {
            throw new DomainRuleException('That outlet is not in your plan on that day.', 'not_in_plan', 422);
        }
        if (in_array($d['type'], ['add', 'move'], true) && !DB::table('campaign_locations')->where('campaign_id', $plan->campaign_id)->where('location_id', $d['location_id'])->exists()) {
            throw new DomainRuleException('That outlet is not part of this project.', 'location_not_in_campaign', 422);
        }
        if (JpChangeRequest::query()->where('agent_id', $user->getKey())->where('status', 'pending')->where('journey_plan_id', $plan->id)->where('location_id', $d['location_id'])->where('weekday', $d['weekday'])->exists()) {
            throw DomainRuleException::conflict('You already have a pending request for this outlet and day.', 'duplicate_request');
        }

        $req = JpChangeRequest::query()->create([
            'client_uuid' => $d['client_uuid'] ?? null, 'journey_plan_id' => $plan->id, 'agent_id' => $user->getKey(), 'type' => $d['type'], 'location_id' => $d['location_id'],
            'weekday' => $d['weekday'], 'to_weekday' => $d['type'] === 'remove' ? null : $target, 'reason' => $d['reason'],
            'latitude' => $d['latitude'] ?? null, 'longitude' => $d['longitude'] ?? null, 'accuracy_m' => $d['accuracy_m'] ?? null,
        ]);
        if ($file = $request->file('photo')) {
            $disk = config('field.uploads.disk');
            $req->update(['proof_disk' => $disk, 'proof_path' => Storage::disk($disk)->putFile("jp-requests/{$req->id}", $file)]);
        }
        AuditService::record('jp_request.created', 'assignments', $req, null, ['type' => $req->type, 'location_id' => $req->location_id, 'weekday' => $req->weekday]);
        $boss = DB::table('agent_profiles')->where('user_id', $user->getKey())->first();
        NotificationService::notify(array_filter([$boss->team_leader_id ?? null, $boss->supervisor_id ?? null]), 'jp.requested', 'Journey plan change requested', "{$user->name}: {$req->reason}", ['jp_request_id' => $req->id]);

        return $this->created($this->present($req->load(['agent:id,name', 'location:id,name,town', 'plan.campaign:id,name'])), 'Request sent.');
    }

    public function approve(Request $request, string $id, JourneyPlanService $plans): JsonResponse
    {
        return $this->decide($request, $id, true, $plans);
    }

    public function reject(Request $request, string $id, JourneyPlanService $plans): JsonResponse
    {
        return $this->decide($request, $id, false, $plans);
    }

    private function decide(Request $request, string $id, bool $approve, JourneyPlanService $plans): JsonResponse
    {
        $this->assertUuid($id);
        $user = $request->user();
        $d = $request->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'max:1000']]);
        $req = $this->scoped($request)->findOrFail($id);
        if ($req->agent_id === $user->getKey()) {
            throw new DomainRuleException('You cannot decide your own request.', 'self_decision', 403);
        }
        if ($req->status !== 'pending') {
            throw DomainRuleException::conflict('This request has already been decided.', 'already_decided');
        }
        DB::transaction(function () use ($req, $approve, $d, $user, $plans) {
            if ($approve) {
                $plan = JourneyPlan::query()->with('stops')->findOrFail($req->journey_plan_id);
                $map = [];
                foreach ($plan->stops as $s) {
                    $map[$s->location_id] ??= ['location_id' => $s->location_id, 'weekdays' => [], 'activity_id' => $s->activity_id, 'start_time' => $s->start_time];
                    $map[$s->location_id]['weekdays'][] = (int) $s->weekday;
                }
                $map[$req->location_id] ??= ['location_id' => $req->location_id, 'weekdays' => [], 'activity_id' => null, 'start_time' => null];
                $days = &$map[$req->location_id]['weekdays'];
                if (in_array($req->type, ['remove', 'move'], true)) {
                    $days = array_values(array_diff($days, [(int) $req->weekday]));
                }
                if (in_array($req->type, ['add', 'move'], true)) {
                    $days[] = (int) $req->to_weekday;
                }
                $days = array_values(array_unique($days));
                unset($days);
                $plans->saveStops($plan, array_values(array_filter($map, fn ($m) => $m['weekdays'] !== [])), $user);
            }
            $req->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user->getKey(), 'decided_at' => now(), 'decision_note' => $d['note'] ?? null]);
            AuditService::record($approve ? 'jp_request.approved' : 'jp_request.rejected', 'assignments', $req, null, ['type' => $req->type, 'agent_id' => $req->agent_id], ['note' => $d['note'] ?? null]);
        });
        NotificationService::notify($req->agent_id, $approve ? 'jp.approved' : 'jp.rejected', $approve ? 'Journey plan change approved' : 'Journey plan change rejected', $d['note'] ?? null, ['jp_request_id' => $req->id]);

        return $this->ok($this->present($req->refresh()->load(['agent:id,name', 'location:id,name,town', 'plan.campaign:id,name', 'decider:id,name'])), $approve ? 'Approved. The route is updated.' : 'Rejected.');
    }

    public function proof(Request $request, string $id): StreamedResponse
    {
        $this->assertUuid($id);
        $req = $this->scoped($request)->findOrFail($id);
        abort_unless($req->proof_path && Storage::disk($req->proof_disk)->exists($req->proof_path), 404);

        return Storage::disk($req->proof_disk)->response($req->proof_path, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
