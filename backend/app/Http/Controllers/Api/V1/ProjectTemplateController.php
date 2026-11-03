<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Projects\ProjectSettings;
use App\Models\ProjectTemplate;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Reusable project setups. The five built-in ones are read-only; administrators add their own (usually by copying one). */
class ProjectTemplateController extends ResourceController
{
    protected string $model = ProjectTemplate::class;
    protected array $search = ['name', 'description'];
    protected array $sortable = ['name', 'created_at'];
    protected string $defaultSort = 'created_at';

    public static function settingsRules(string $prefix = 'settings'): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "$prefix.outlet_source" => ['nullable', Rule::in(ProjectSettings::SOURCES)],
            "$prefix.journey_plan" => ['nullable', 'boolean'],
            "$prefix.tasks" => ['nullable', 'array'],
            "$prefix.tasks.*" => ['boolean'],
            "$prefix.review" => ['nullable', Rule::in(ProjectSettings::REVIEW)],
            "$prefix.capture_geo" => ['nullable', Rule::in(ProjectSettings::CAPTURE_GEO)],
            "$prefix.visit_photos" => ['nullable', 'boolean'],
            "$prefix.min_photos" => ['nullable', 'integer', 'min:0', 'max:50'],
            "$prefix.visit_label" => ['nullable', 'string', 'max:40'],
            "$prefix.outlet_type" => ['nullable', 'string', 'max:40'],
            "$prefix.targets" => ['nullable', 'array'],
            "$prefix.targets.osa_pct" => ['nullable', 'numeric', 'min:0', 'max:100'],
            "$prefix.targets.sos_pct" => ['nullable', 'numeric', 'min:0', 'max:100'],
            "$prefix.targets.coverage_pct" => ['nullable', 'numeric', 'min:0', 'max:100'],
            "$prefix.targets.visits_per_outlet" => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('project_templates', 'name')->ignore($model?->getKey())],
            'description' => ['nullable', 'string', 'max:1000'],
        ] + self::settingsRules();
    }

    protected function creating(array $data, Request $request): array
    {
        $data = parent::creating($data, $request);
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(5));
        $data['is_system'] = false;
        $data['settings'] = $data['settings'] ?? ProjectSettings::DEFAULTS;

        return $data;
    }

    protected function present(Model $model): array
    {
        $out = $model->toArray();
        $out['settings'] = ProjectSettings::normalize($model->settings);

        return $out;
    }

    protected function deleting(Model $model): void
    {
        if (\App\Models\Campaign::query()->where('template_id', $model->getKey())->exists()) {
            throw DomainRuleException::conflict('Campaigns were created from this template. Their settings are kept, but the template cannot be deleted while it is in use.', 'template_in_use');
        }
    }
}
