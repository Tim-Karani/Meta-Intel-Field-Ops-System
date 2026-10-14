<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Audit\AuditService;
use App\Domain\Notifications\NotificationService;
use App\Enums\IssueStatus;
use App\Models\Campaign;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Location;
use App\Models\User;
use App\Models\Visit;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IssueController extends ResourceController
{
    protected string $model = Issue::class;
    protected array $with = ['campaign:id,name', 'location:id,name', 'reporter:id,name', 'assignee:id,name'];
    protected array $search = ['title', 'description', 'category'];
    protected array $filters = ['status' => 'status', 'priority' => 'priority', 'campaign_id' => 'campaign_id', 'assigned_to' => 'assigned_to', 'reported_by' => 'reported_by', 'category' => 'category', 'visit_id' => 'visit_id', 'location_id' => 'location_id'];
    protected array $sortable = ['created_at', 'priority', 'status', 'title'];
    protected string $defaultSort = '-created_at';

    protected function rules(?Model $model = null): array
    {
        return [
            'visit_id' => ['nullable', 'uuid', new Accessible(Visit::class)],
            'campaign_id' => ['nullable', 'uuid', new Accessible(Campaign::class)],
            'location_id' => ['nullable', 'uuid', new Accessible(Location::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:60'],
            'priority' => ['in:low,medium,high,critical'],
            'client_uuid' => $model ? ['prohibited'] : ['nullable', 'uuid'],
        ];
    }

    protected function present(Model $model): array
    {
        $out = $model->toArray();
        $out['status'] = $model->status->value;
        $out['allowed_transitions'] = array_map(fn ($s) => $s->value, $model->status->next());

        return $out;
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $issue = $this->findScoped($request, $id);
        $this->authorize('view', $issue);
        $issue->load('comments.user:id,name', 'visit:id,visit_date,status');

        return $this->ok($this->present($issue));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Issue::class);
        $data = $request->validate($this->rules());
        $user = $request->user();

        if (!empty($data['client_uuid'])) {
            $dup = Issue::query()->where('reported_by', $user->getKey())->where('client_uuid', $data['client_uuid'])->first();
            if ($dup) {
                return $this->ok($this->present($dup->load($this->with)), 'Issue already received.');
            }
        }

        // An issue raised inside a visit inherits its campaign / activity / location.
        if (!empty($data['visit_id'])) {
            $visit = Visit::query()->findOrFail($data['visit_id']);
            $data['campaign_id'] = $visit->campaign_id;
            $data['activity_id'] = $visit->activity_id;
            $data['location_id'] = $visit->location_id;
        }
        $data['reported_by'] = $user->getKey();
        $data['status'] = IssueStatus::Open->value;

        $issue = DB::transaction(function () use ($data, $user) {
            $issue = new Issue;
            $issue->forceFill(array_intersect_key($data, array_flip($issue->getFillable())))->save();
            IssueComment::query()->create(['issue_id' => $issue->id, 'user_id' => $user->getKey(), 'type' => 'status', 'body' => 'Issue reported.']);

            return $issue;
        });

        $recipients = $issue->visit_id
            ? NotificationService::visitReviewers($issue->visit()->first())
            : DB::table('campaign_users')->where('campaign_id', $issue->campaign_id)->pluck('user_id');
        NotificationService::notify($recipients->reject(fn ($id) => $id === $user->getKey()), 'issue.reported', 'New issue: '.$issue->title, null, ['issue_id' => $issue->id]);

        return $this->created($this->present($issue->load($this->with)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $issue = $this->findScoped($request, $id);
        $this->authorize('update', $issue);
        if (in_array($issue->status, [IssueStatus::Resolved, IssueStatus::Closed], true)) {
            throw DomainRuleException::conflict('Resolved issues cannot be edited. Re-open the issue first.', 'issue_locked');
        }
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'], 'priority' => ['sometimes', 'in:low,medium,high,critical'],
        ]);
        $issue->update($data);

        return $this->ok($this->present($issue->fresh($this->with)), 'Updated successfully.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        abort(405, 'Issues are closed, not deleted.');
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $issue = $this->findScoped($request, $id);
        $this->authorize('assign', $issue);
        $data = $request->validate([
            'assigned_to' => ['required', 'uuid', new Accessible(User::class, fn ($q) => $q->where('users.status', 'active')->whereNull('users.client_id'))],
        ]);
        if (in_array($issue->status, [IssueStatus::Closed], true)) {
            throw DomainRuleException::conflict('Closed issues cannot be assigned.', 'issue_closed');
        }

        DB::transaction(function () use ($issue, $data, $request) {
            $from = $issue->status;
            $issue->update(['assigned_to' => $data['assigned_to'], 'status' => $from === IssueStatus::Open ? IssueStatus::Assigned : $from]);
            $name = User::query()->whereKey($data['assigned_to'])->value('name');
            IssueComment::query()->create(['issue_id' => $issue->id, 'user_id' => $request->user()->getKey(), 'type' => 'assignment', 'body' => "Assigned to {$name}."]);
            AuditService::record('issue.assigned', 'issues', $issue, null, ['assigned_to' => $data['assigned_to']]);
        });
        NotificationService::notify($data['assigned_to'], 'issue.assigned', 'Issue assigned to you: '.$issue->title, null, ['issue_id' => $issue->id]);

        return $this->ok($this->present($issue->fresh($this->with)), 'Issue assigned.');
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $issue = $this->findScoped($request, $id);
        $this->authorize('resolve', $issue);
        $data = $request->validate([
            'status' => ['required', Rule::enum(IssueStatus::class)],
            'resolution' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $to = IssueStatus::from($data['status']);
        if (!$issue->status->canMoveTo($to)) {
            throw DomainRuleException::conflict("Issue cannot move from {$issue->status->value} to {$to->value}.", 'invalid_transition');
        }
        if ($to === IssueStatus::Resolved && trim((string) ($data['resolution'] ?? '')) === '') {
            throw new DomainRuleException('A resolution description is required.', 'resolution_required');
        }

        DB::transaction(function () use ($issue, $to, $data, $request) {
            $from = $issue->status;
            $attrs = ['status' => $to];
            match ($to) {
                IssueStatus::Escalated => $attrs['escalated_at'] = now(),
                IssueStatus::Resolved => $attrs += ['resolution' => $data['resolution'], 'resolved_at' => now()],
                IssueStatus::Closed => $attrs['closed_at'] = now(),
                IssueStatus::InProgress => $from === IssueStatus::Resolved ? $attrs += ['resolved_at' => null] : null,
                default => null,
            };
            $issue->update($attrs);
            IssueComment::query()->create([
                'issue_id' => $issue->id, 'user_id' => $request->user()->getKey(), 'type' => 'status',
                'body' => "Status changed from {$from->value} to {$to->value}.".(!empty($data['note']) ? ' '.$data['note'] : ''),
            ]);
            AuditService::record('issue.'.$to->value, 'issues', $issue, ['status' => $from->value], ['status' => $to->value]);
        });
        if ($to === IssueStatus::Resolved && $issue->reported_by !== $request->user()->getKey()) {
            NotificationService::notify($issue->reported_by, 'issue.resolved', 'Issue resolved: '.$issue->title, $data['resolution'], ['issue_id' => $issue->id]);
        }

        return $this->ok($this->present($issue->fresh($this->with)), 'Issue updated.');
    }

    public function comment(Request $request, string $id): JsonResponse
    {
        $issue = $this->findScoped($request, $id);
        $this->authorize('comment', $issue);
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']]);

        $comment = IssueComment::query()->create(['issue_id' => $issue->id, 'user_id' => $request->user()->getKey(), 'type' => 'comment', 'body' => $data['body']]);
        NotificationService::notify(collect([$issue->reported_by, $issue->assigned_to])->filter()->reject(fn ($u) => $u === $request->user()->getKey()),
            'issue.comment', 'New comment on: '.$issue->title, mb_substr($data['body'], 0, 140), ['issue_id' => $issue->id]);

        return $this->created($comment->load('user:id,name'));
    }

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        if ($request->boolean('open_only')) {
            $query->whereNotIn('issues.status', ['resolved', 'closed']);
        }

        return $query;
    }
}
