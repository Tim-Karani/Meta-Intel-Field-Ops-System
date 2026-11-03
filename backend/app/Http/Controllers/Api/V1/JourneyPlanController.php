<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Assignments\JourneyPlanService;
use App\Enums\Role as RoleEnum;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\JourneyPlan;
use App\Models\User;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Fixed journey plans (one per agent per campaign) and their weekday stops.
 *
 *   GET/POST/PATCH/DELETE journey-plans       the plan itself (agent, campaign, validity dates, status)
 *   PUT  journey-plans/{id}/stops             replace the stops: [{location_id, weekdays:[1..7], activity_id?, start_time?}]
 *   POST journey-plans/{id}/generate          create any missing visits for the next two weeks now
 *   POST journey-plans/{id}/status            active | paused | ended
 */
class JourneyPlanController extends ResourceController
{
    protected string $model = JourneyPlan::class;
    protected array $with = ['campaign:id,name,code', 'agent:id,name'];
    protected array $withCount = ['stops'];
    protected array $filters = ['campaign_id' => 'campaign_id', 'agent_id' => 'agent_id', 'status' => 'status'];
    protected array $sortable = ['created_at', 'status'];
    protected string $defaultSort = '-created_at';

    public function __construct(private JourneyPlanService $service) {}

    protected function rules(?Model $model = null): array
    {
        return [
            'campaign_id' => ['required', 'uuid', new Accessible(Campaign::class)],
            'agent_id' => ['required', 'uuid', Rule::exists('users', 'id')],
            'name' => ['nullable', 'string', 'max:160'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    protected function creating(array $data, Request $request): array
    {
        $data = parent::creating($data, $request);
        $agent = AccessScope::assignableAgents(User::query(), $request->user())->whereKey($data['agent_id'])->first();
        if (!$agent) {
            throw new DomainRuleException('That agent is not available for you to plan for.', 'agent_not_assignable', 422);
        }
        if (JourneyPlan::query()->where('campaign_id', $data['campaign_id'])->where('agent_id', $data['agent_id'])->exists()) {
            throw DomainRuleException::conflict('This agent already has a journey plan in this campaign. Open it to change the outlets.', 'plan_exists');
        }
        $data['status'] = 'active';

        return $data;
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($request->has('agent_id') || $request->has('campaign_id')) {
            throw DomainRuleException::conflict('A plan belongs to one agent and one campaign. Create another plan instead.', 'plan_locked');
        }

        return parent::update($request, $id);
    }

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        return $query->select('journey_plans.*')->addSelect(['outlets_count' => DB::table('journey_plan_stops')->selectRaw('count(distinct location_id)')->whereColumn('journey_plan_id', 'journey_plans.id')]);
    }

    /** Which other agents already cover each outlet of a campaign, so a planner can avoid double cover. */
    public function owners(Request $request): JsonResponse
    {
        $data = $request->validate(['campaign_id' => ['required', 'uuid', new Accessible(Campaign::class)], 'except_plan' => ['nullable', 'uuid']]);
        $plans = AccessScope::scope(JourneyPlan::query(), $request->user())->where('campaign_id', $data['campaign_id'])->where('status', 'active')
            ->when($data['except_plan'] ?? null, fn ($q, $v) => $q->where('id', '!=', $v))->with('agent:id,name')->get(['id', 'agent_id']);
        $names = $plans->pluck('agent.name', 'id');
        $owners = DB::table('journey_plan_stops')->whereIn('journey_plan_id', $plans->pluck('id'))->select('location_id', 'journey_plan_id')->distinct()->get()
            ->groupBy('location_id')->map(fn ($rows) => $rows->map(fn ($r) => $names[$r->journey_plan_id] ?? null)->filter()->unique()->values()->all());

        return $this->ok($owners->all());
    }

    protected function present(Model $model): array
    {
        $out = $model->toArray();
        if ($model->relationLoaded('stops')) {
            $out['stops'] = $this->groupedStops($model);
        }

        return $out;
    }

    /** Stops folded back to one row per outlet with its weekdays, in plan order. */
    private function groupedStops(JourneyPlan $plan): array
    {
        return $plan->stops->groupBy('location_id')->map(fn ($rows) => [
            'location_id' => $rows->first()->location_id,
            'location' => $rows->first()->location ? $rows->first()->location->only(['id', 'name', 'town']) + ['chain' => $rows->first()->location->channel?->name] : null,
            'activity_id' => $rows->first()->activity_id,
            'start_time' => $rows->first()->start_time ? substr($rows->first()->start_time, 0, 5) : null,
            'weekdays' => $rows->pluck('weekday')->sort()->values()->all(),
            'days' => $rows->sortBy('weekday')->map(fn ($r) => ['weekday' => (int) $r->weekday, 'activity_id' => $r->activity_id, 'start_time' => $r->start_time ? substr($r->start_time, 0, 5) : null])->values()->all(),
            'sequence' => $rows->min('sequence'),
        ])->sortBy('sequence')->values()->all();
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $plan = $this->findScoped($request, $id);
        $this->authorize('view', $plan);
        $plan->load('stops.location.channel:id,name');

        return $this->ok($this->present($plan));
    }

    public function stops(Request $request, string $id): JsonResponse
    {
        $plan = $this->findScoped($request, $id);
        $this->authorize('update', $plan);
        $data = $request->validate([
            'stops' => ['present', 'array', 'max:3000'],
            'stops.*.location_id' => ['required', 'uuid', new Accessible(\App\Models\Location::class)],
            'stops.*.weekdays' => ['required', 'array', 'min:1'],
            'stops.*.weekdays.*' => ['integer', 'between:1,7'],
            'stops.*.activity_id' => ['nullable', 'uuid'],
            'stops.*.start_time' => ['nullable', 'date_format:H:i'],
        ]);
        foreach ($data['stops'] as $s) {
            if (!empty($s['activity_id']) && !Activity::query()->where('campaign_id', $plan->campaign_id)->whereKey($s['activity_id'])->exists()) {
                throw new DomainRuleException('An activity in the plan does not belong to this campaign.', 'activity_not_in_campaign', 422);
            }
        }
        $plan = $this->service->saveStops($plan, $data['stops'], $request->user());

        return $this->ok($this->present($plan->load('stops.location.channel:id,name')), 'Journey plan saved. Visits for the next two weeks were created.');
    }

    public function generate(Request $request, string $id): JsonResponse
    {
        $plan = $this->findScoped($request, $id);
        $this->authorize('update', $plan);
        $n = $this->service->generate($plan, $request->user());

        return $this->ok(['created' => $n], $n ? "{$n} visit(s) created." : 'Nothing new to create: the next two weeks are already scheduled.');
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $plan = $this->findScoped($request, $id);
        $this->authorize('update', $plan);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'ended'])]]);

        return $this->ok($this->present($this->service->setStatus($plan, $data['status'])->load(['campaign:id,name,code', 'agent:id,name'])), 'Journey plan '.$data['status'].'.');
    }

    protected function deleting(Model $model): void
    {
        $this->service->setStatus($model, 'ended');   // future untouched visits are cancelled; history stays
    }
}
