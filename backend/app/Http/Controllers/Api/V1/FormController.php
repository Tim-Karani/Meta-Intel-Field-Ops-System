<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Domain\Forms\FormStructureService;
use App\Enums\QuestionType;
use App\Models\Form;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FormController extends ResourceController
{
    protected string $model = Form::class;
    protected array $search = ['name', 'description'];
    protected array $filters = ['status' => 'status', 'group_id' => 'group_id'];
    protected array $sortable = ['name', 'version', 'status', 'created_at'];
    protected string $defaultSort = '-created_at';

    public function __construct(private FormStructureService $structure) {}

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'requires_live_capture' => ['boolean'],
        ];
    }

    protected function present(Model $model): array
    {
        return $model->toArray();
    }

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        return $query->withCount('questions');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $form = $this->findScoped($request, $id);
        $this->authorize('view', $form);

        return $this->ok($form->toArray() + ['sections' => $this->structure->export($form)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Form::class);
        $data = $request->validate($this->rules() + $this->structureRules());

        $form = DB::transaction(function () use ($data, $request) {
            $form = Form::query()->create([
                'group_id' => (string) Str::uuid(), 'version' => 1, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'requires_live_capture' => $data['requires_live_capture'] ?? false, 'status' => 'draft', 'created_by' => $request->user()->getKey(),
            ]);
            if (!empty($data['sections'])) {
                $this->structure->replace($form, $data['sections']);
            }

            return $form;
        });

        return $this->created($form->toArray() + ['sections' => $this->structure->export($form)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $form = $this->findScoped($request, $id);
        $this->authorize('update', $form);
        $this->structure->assertDraft($form);

        return parent::update($request, $id);
    }

    /** PUT /forms/{id}/structure: replace sections/questions of a draft form. */
    public function saveStructure(Request $request, string $id): JsonResponse
    {
        $form = $this->findScoped($request, $id);
        $this->authorize('update', $form);
        $data = $request->validate($this->structureRules(true));

        $this->structure->replace($form, $data['sections']);
        AuditService::record('form.structure_saved', 'forms', $form, null, ['questions' => $form->questions()->count()]);

        return $this->ok($form->toArray() + ['sections' => $this->structure->export($form)], 'Form saved.');
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $form = $this->findScoped($request, $id);
        $this->authorize('update', $form);
        $form = $this->structure->publish($form);
        AuditService::record('form.published', 'forms', $form, ['status' => 'draft'], ['status' => 'published', 'version' => $form->version]);

        return $this->ok($form, 'Form published.');
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $form = $this->findScoped($request, $id);
        $this->authorize('update', $form);
        if ($form->status !== 'published') {
            throw DomainRuleException::conflict('Only published forms can be archived.', 'form_not_published');
        }
        $form->update(['status' => 'archived']);
        AuditService::record('form.archived', 'forms', $form, ['status' => 'published'], ['status' => 'archived']);

        return $this->ok($form->refresh(), 'Form archived.');
    }

    public function newVersion(Request $request, string $id): JsonResponse
    {
        $form = $this->findScoped($request, $id);
        $this->authorize('update', $form);
        if ($form->status === 'draft') {
            throw DomainRuleException::conflict('This form is still a draft; edit it directly.', 'form_is_draft');
        }
        if (Form::query()->where('group_id', $form->group_id)->where('status', 'draft')->exists()) {
            throw DomainRuleException::conflict('A draft version of this form already exists.', 'draft_exists');
        }
        $copy = $this->structure->copy($form, $request->user()->getKey(), true);
        AuditService::record('form.version_created', 'forms', $copy, null, ['from' => $form->id, 'version' => $copy->version]);

        return $this->created($copy->toArray() + ['sections' => $this->structure->export($copy)]);
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $form = $this->findScoped($request, $id);
        $this->authorize('view', $form);
        $this->authorize('create', Form::class);
        $name = $request->validate(['name' => ['nullable', 'string', 'max:255']])['name'] ?? null;
        $copy = $this->structure->copy($form, $request->user()->getKey(), false, $name);

        return $this->created($copy->toArray() + ['sections' => $this->structure->export($copy)]);
    }

    protected function deleting(Model $model): void
    {
        if ($model->status !== 'draft') {
            throw DomainRuleException::conflict('Only draft forms can be deleted. Archive published forms instead.', 'form_not_draft');
        }
        if (DB::table('campaign_forms')->where('form_id', $model->id)->exists() || DB::table('activities')->where('form_id', $model->id)->exists()) {
            throw DomainRuleException::conflict('This form is in use.', 'form_in_use');
        }
    }

    private function structureRules(bool $required = false): array
    {
        return [
            'sections' => [$required ? 'required' : 'nullable', 'array', 'min:1', 'max:50'],
            'sections.*.title' => ['required', 'string', 'max:255'],
            'sections.*.description' => ['nullable', 'string', 'max:1000'],
            'sections.*.questions' => ['required', 'array', 'min:1'],
            'sections.*.questions.*.ref' => ['required', 'string', 'max:64'],
            'sections.*.questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'sections.*.questions.*.label' => ['required', 'string', 'max:500'],
            'sections.*.questions.*.help_text' => ['nullable', 'string', 'max:1000'],
            'sections.*.questions.*.required' => ['boolean'],
            'sections.*.questions.*.evidence_required' => ['boolean'],
            'sections.*.questions.*.validation' => ['nullable', 'array'],
            'sections.*.questions.*.validation.min' => ['nullable', 'numeric'],
            'sections.*.questions.*.validation.max' => ['nullable', 'numeric'],
            'sections.*.questions.*.validation.min_length' => ['nullable', 'integer', 'min:0'],
            'sections.*.questions.*.validation.max_length' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'sections.*.questions.*.validation.pattern' => ['nullable', 'string', 'max:200'],
            'sections.*.questions.*.scoring' => ['nullable', 'array'],
            'sections.*.questions.*.settings' => ['nullable', 'array'],
            'sections.*.questions.*.options' => ['nullable', 'array', 'max:100'],
            'sections.*.questions.*.options.*.label' => ['required', 'string', 'max:255'],
            'sections.*.questions.*.options.*.value' => ['required', 'string', 'max:255'],
            'sections.*.questions.*.options.*.score' => ['nullable', 'numeric'],
            'sections.*.questions.*.conditions' => ['nullable', 'array', 'max:10'],
            'sections.*.questions.*.conditions.*.depends_on' => ['required', 'string', 'max:64'],
            'sections.*.questions.*.conditions.*.operator' => ['required', Rule::in(FormStructureService::OPERATORS)],
            'sections.*.questions.*.conditions.*.value' => ['nullable'],
        ];
    }
}
