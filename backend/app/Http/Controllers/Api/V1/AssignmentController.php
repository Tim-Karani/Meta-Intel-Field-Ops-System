<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Assignments\AssignmentService;
use App\Enums\AssignmentStatus;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Campaign;
use App\Models\Location;
use App\Models\User;
use App\Rules\Accessible;
use App\Rules\AssignableAgent;
use App\Support\ApiResponse;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssignmentController extends ResourceController
{
    protected string $model = Assignment::class;
    protected array $with = ['campaign:id,name,code', 'activity:id,name', 'agent:id,name', 'location:id,name,town,latitude,longitude,geofence_radius_m', 'supervisor:id,name'];
    protected array $filters = ['campaign_id' => 'campaign_id', 'agent_id' => 'agent_id', 'status' => 'status', 'location_id' => 'location_id', 'activity_id' => 'activity_id', 'supervisor_id' => 'supervisor_id'];
    protected array $sortable = ['scheduled_date', 'status', 'created_at'];
    protected string $defaultSort = '-scheduled_date';

    public function __construct(private AssignmentService $service) {}

    protected function rules(?Model $model = null): array
    {
        return [
            'campaign_id' => ['required', 'uuid', new Accessible(Campaign::class)],
            'activity_id' => ['nullable', 'uuid', new Accessible(Activity::class)],
            'agent_id' => ['required', 'uuid', new AssignableAgent()],
            'location_id' => ['required', 'uuid', new Accessible(Location::class)],
            'supervisor_id' => ['nullable', 'uuid', new Accessible(User::class)],
            'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'target' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']]);
        if ($from = $request->query('date_from')) {
            $query->whereDate('assignments.scheduled_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('assignments.scheduled_date', '<=', $to);
        }

        return $query;
    }

    protected function present(Model $model): array
    {
        $a = $model->toArray();
        $a['status'] = $model->status->value;
        $a['allowed_transitions'] = array_map(fn ($s) => $s->value, $model->status->next());

        return $a;
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Assignment::class);
        $data = $request->validate($this->rules());
        $assignment = $this->service->create($data, $request->user());

        return ApiResponse::created($this->present($assignment->load($this->with)));
    }

    /** All-or-nothing: one invalid row rejects the whole batch (errors are keyed by row index). */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorize('create', Assignment::class);
        $request->validate(['assignments' => ['required', 'array', 'min:1', 'max:200']]);

        $rules = collect($this->rules())->mapWithKeys(fn ($r, $k) => ["assignments.*.$k" => $r])->all();
        $data = $request->validate($rules);

        $created = DB::transaction(fn () => collect($data['assignments'])->map(fn ($row) => $this->service->create($row, $request->user())->id)->all());

        return ApiResponse::created(['created' => count($created), 'ids' => $created], count($created).' assignments created.');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $assignment = $this->findScoped($request, $id);
        $this->authorize('update', $assignment);
        if (!in_array($assignment->status, [AssignmentStatus::Pending, AssignmentStatus::Accepted, AssignmentStatus::Overdue], true)) {
            throw DomainRuleException::conflict('Only assignments that have not started can be edited.', 'assignment_locked');
        }
        $data = $request->validate([
            'scheduled_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i', 'after:start_time'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'supervisor_id' => ['sometimes', 'nullable', 'uuid', new Accessible(User::class)],
        ]);

        $assignment->update($data);
        if (isset($data['scheduled_date']) && $assignment->status === AssignmentStatus::Overdue && $assignment->scheduled_date->isFuture()) {
            $assignment->update(['status' => AssignmentStatus::Pending]);
        }

        return $this->ok($this->present($assignment->fresh($this->with)), 'Updated successfully.');
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        $assignment = $this->findScoped($request, $id);
        $this->authorize('respond', $assignment);

        return $this->ok($this->present($this->service->transition($assignment, AssignmentStatus::Accepted, $request->user())->load($this->with)), 'Assignment accepted.');
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $assignment = $this->findScoped($request, $id);
        $this->authorize('update', $assignment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        if ($assignment->visits()->whereIn('status', ['started', 'completed'])->exists()) {
            throw DomainRuleException::conflict('An agent is in the middle of this visit. It cannot be cancelled now.', 'visit_in_progress');
        }

        return $this->ok($this->present($this->service->transition($assignment, AssignmentStatus::Cancelled, $request->user(), $data['reason'])->load($this->with)), 'Assignment cancelled.');
    }

    public function reassign(Request $request, string $id): JsonResponse
    {
        $assignment = $this->findScoped($request, $id);
        $this->authorize('update', $assignment);
        $data = $request->validate(['agent_id' => ['required', 'uuid', new AssignableAgent(), Rule::notIn([$assignment->agent_id])]]);

        return $this->ok($this->present($this->service->reassign($assignment, $data['agent_id'], $request->user())->load($this->with)), 'Assignment reassigned.');
    }

    /** Agents the current user may assign work to (for pickers). */
    public function agents(Request $request): JsonResponse
    {
        $this->authorize('create', Assignment::class);
        $q = AccessScope::assignableAgents(User::query(), $request->user())->select('users.id', 'users.name');
        if ($term = $this->searchTerm($request)) {
            $q->where('users.name', 'ilike', '%'.addcslashes($term, '%_\\').'%');
        }

        return $this->ok($q->orderBy('users.name')->limit(200)->get());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        abort(405, 'Assignments are cancelled, not deleted.');
    }
}
