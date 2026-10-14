<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Visits\VisitService;
use App\Http\Requests\GpsReadingRequest;
use App\Http\Resources\VisitResource;
use App\Models\Assignment;
use App\Models\Visit;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VisitController extends ApiController
{
    private const DETAIL = [
        'campaign:id,name', 'activity:id,name', 'location', 'agent:id,name', 'reviewer:id,name', 'checkin', 'checkout',
        'responses', 'sales.items.product:id,name,sku', 'osaRecords.product:id,name', 'merchandisingRecords', 'photos', 'issues', 'events',
    ];

    public function __construct(private VisitService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Visit::class);
        $request->validate([
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, \App\Enums\VisitStatus::cases()))],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $q = AccessScope::scope(Visit::query(), $request->user())->with(['campaign:id,name', 'location:id,name,town', 'agent:id,name', 'activity:id,name']);
        foreach (['status', 'campaign_id', 'agent_id', 'location_id', 'activity_id', 'gps_outcome', 'assignment_id'] as $f) {
            if (($v = $this->filterValue($request, $f, str_ends_with($f, '_id'))) !== null) {
                $q->where("visits.$f", $v);
            }
        }
        if ($from = $request->query('date_from')) {
            $q->where('visits.visit_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $q->where('visits.visit_date', '<=', $to);
        }
        if ($request->boolean('needs_review')) {
            $q->whereIn('visits.status', ['submitted', 'under_review']);
        }
        $sort = in_array($request->query('sort'), ['visit_date', '-visit_date', 'submitted_at', '-submitted_at', 'status'], true) ? (string) $request->query('sort') : '-visit_date';
        $q->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('visits.id');

        $page = $q->paginate($this->perPage($request));

        return ApiResponse::paginated($page, VisitResource::collection($page->getCollection())->resolve($request));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $visit = $this->find($request, $id, self::DETAIL);
        $this->authorize('view', $visit);

        return $this->ok((new VisitResource($visit))->resolve($request));
    }

    /** POST /visits — create the visit for one of the agent's own assignments (idempotent on client_uuid). */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Visit::class);
        $data = $request->validate([
            'assignment_id' => ['required', 'uuid'],
            'client_uuid' => ['nullable', 'uuid'],
            'device_id' => ['nullable', 'string', 'max:120'],
        ]);
        // Only the agent's own assignment is visible to them; anything else looks like a missing row.
        $assignment = AccessScope::scope(Assignment::query(), $request->user())->findOrFail($data['assignment_id']);

        $visit = $this->service->start($request->user(), $assignment, $data['client_uuid'] ?? null, $data['device_id'] ?? null);
        $payload = (new VisitResource($visit->load(['campaign:id,name', 'location', 'activity:id,name'])))->resolve($request);

        return $visit->wasRecentlyCreated ? $this->created($payload, 'Visit created.') : $this->ok($payload, 'Visit already exists.');
    }

    public function checkIn(GpsReadingRequest $request, string $id): JsonResponse
    {
        $visit = $this->find($request, $id);
        $this->authorize('work', $visit);

        return $this->ok($this->resource($this->service->checkIn($visit, $request->user(), $request->validated()), $request), 'Checked in.');
    }

    public function checkOut(GpsReadingRequest $request, string $id): JsonResponse
    {
        $visit = $this->find($request, $id);
        $this->authorize('work', $visit);

        return $this->ok($this->resource($this->service->checkOut($visit, $request->user(), $request->validated()), $request), 'Checked out.');
    }

    /** PUT /visits/{id}/responses  { answers: { question_id: value } } */
    public function responses(Request $request, string $id): JsonResponse
    {
        $visit = $this->find($request, $id);
        $this->authorize('work', $visit);
        $data = $request->validate(['answers' => ['required', 'array', 'max:500']]);

        $this->service->saveResponses($visit, $data['answers']);

        return $this->ok($visit->load('responses')->responses->pluck('value', 'question_id'), 'Responses saved.');
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $visit = $this->find($request, $id);
        $this->authorize('submit', $visit);

        return $this->ok($this->resource($this->service->submit($visit, $request->user()), $request), 'Visit submitted for review.');
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $visit = $this->find($request, $id);
        $this->authorize('review', $visit);

        return $this->ok($this->resource($this->service->startReview($visit, $request->user()), $request), 'Review started.');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $visit = $this->find($request, $id);
        $this->authorize('approve', $visit);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->resource($this->service->approve($visit, $request->user(), $data['note'] ?? null), $request), 'Visit approved.');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $visit = $this->find($request, $id);
        $this->authorize('reject', $visit);
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->ok($this->resource($this->service->reject($visit, $request->user(), $data['note']), $request), 'Visit rejected.');
    }

    private function find(Request $request, string $id, array $with = []): Visit
    {
        $this->assertUuid($id);

        return AccessScope::scope(Visit::query(), $request->user())->with($with)->findOrFail($id);
    }

    private function resource(Visit $visit, Request $request): array
    {
        return (new VisitResource($visit->load(self::DETAIL)))->resolve($request);
    }
}
