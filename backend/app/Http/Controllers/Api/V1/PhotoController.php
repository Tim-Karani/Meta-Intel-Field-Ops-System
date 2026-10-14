<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Audit\AuditService;
use App\Domain\Evidence\EvidenceStorage;
use App\Domain\Visits\VisitService;
use App\Models\FormQuestion;
use App\Models\Photo;
use App\Models\Visit;
use App\Support\ApiResponse;
use App\Support\DomainRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends ApiController
{
    public function __construct(private EvidenceStorage $storage, private VisitService $visits) {}

    /** GET /photos — evidence gallery (scoped). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Photo::class);
        $q = AccessScope::scope(Photo::query(), $request->user())->with(['visit:id,campaign_id,location_id,agent_id,visit_date', 'visit.location:id,name', 'visit.campaign:id,name', 'photographer:id,name']);
        foreach (['visit_id', 'review_status', 'kind'] as $f) {
            if (($v = $this->filterValue($request, $f, $f === 'visit_id')) !== null) {
                $q->where("photos.$f", $v);
            }
        }
        if (($c = $this->filterValue($request, 'campaign_id', true)) !== null) {
            $q->whereIn('photos.visit_id', Visit::query()->where('campaign_id', $c)->select('id'));
        }
        $page = $q->orderByDesc('photos.created_at')->orderBy('photos.id')->paginate($this->perPage($request));

        return ApiResponse::paginated($page, $page->getCollection()->map(fn ($p) => $this->present($p))->all());
    }

    /** POST /visits/{visit}/photos (multipart) */
    public function store(Request $request, string $visit): JsonResponse
    {
        $this->assertUuid($visit);
        $v = AccessScope::scope(Visit::query(), $request->user())->findOrFail($visit);
        $this->authorize('work', $v);
        $this->authorize('create', Photo::class);
        $this->visits->assertEditable($v);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('field.uploads.max_kb')],
            'client_uuid' => ['nullable', 'uuid'],
            'question_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', Rule::in(['evidence', 'signature'])],
            'captured_at' => ['nullable', 'date', 'before_or_equal:+5 minutes'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        if (!empty($data['question_id']) && !FormQuestion::query()->whereKey($data['question_id'])->where('form_id', $v->form_id)->exists()) {
            throw new DomainRuleException('The question does not belong to this visit\'s form.', 'invalid_question');
        }

        $photo = $this->storage->storePhoto($request->file('file'), $v, $request->user(), $data);

        return $photo->wasRecentlyCreated ? $this->created($this->present($photo), 'Photo uploaded.') : $this->ok($this->present($photo), 'Photo already received.');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $photo = $this->find($request, $id);
        $this->authorize('view', $photo);

        return $this->ok($this->present($photo));
    }

    /** GET /photos/{id}/file — authorised streaming; the file is never publicly addressable. */
    public function file(Request $request, string $id): StreamedResponse
    {
        $photo = $this->find($request, $id);
        $this->authorize('view', $photo);
        $disk = Storage::disk($photo->disk);
        abort_unless($disk->exists($photo->path), 404);

        return $disk->response($photo->path, null, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="evidence-'.$photo->id.'.jpg"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $photo = $this->find($request, $id);
        $this->authorize('update', $photo);
        if ($photo->taken_by === $request->user()->getKey()) {
            throw new DomainRuleException('You cannot review your own evidence.', 'forbidden', 403);
        }
        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'rejected'])],
            'note' => [Rule::requiredIf($request->input('status') === 'rejected'), 'nullable', 'string', 'max:1000'],
        ]);

        $before = $photo->review_status;
        $photo->update(['review_status' => $data['status'], 'review_note' => $data['note'] ?? null, 'reviewed_by' => $request->user()->getKey(), 'reviewed_at' => now()]);
        AuditService::record('photo.reviewed', 'evidence', $photo, ['review_status' => $before], ['review_status' => $data['status']], array_filter(['note' => $data['note'] ?? null]));

        return $this->ok($this->present($photo->refresh()), 'Photo reviewed.');
    }

    /** Agents may remove their own photos while the visit is still editable. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $photo = $this->find($request, $id);
        $this->authorize('view', $photo);
        $visit = Visit::query()->findOrFail($photo->visit_id);
        $this->authorize('work', $visit);
        if ($photo->taken_by !== $request->user()->getKey()) {
            throw new DomainRuleException('You can only delete your own photos.', 'forbidden', 403);
        }
        $this->visits->assertEditable($visit);

        Storage::disk($photo->disk)->delete($photo->path);
        AuditService::record('photo.deleted', 'evidence', $photo, ['visit_id' => $photo->visit_id, 'sha256' => $photo->sha256]);
        $photo->delete();

        return $this->ok(null, 'Photo deleted.');
    }

    private function find(Request $request, string $id): Photo
    {
        $this->assertUuid($id);

        return AccessScope::scope(Photo::query(), $request->user())->findOrFail($id);
    }

    private function present(Photo $p): array
    {
        return $p->toArray() + ['url' => "/api/v1/photos/{$p->id}/file"];
    }
}
