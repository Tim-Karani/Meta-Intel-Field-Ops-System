<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assignments\CampaignService;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Client;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CampaignController extends ResourceController
{
    protected string $model = Campaign::class;
    protected array $with = ['client:id,name'];
    protected array $withCount = ['activities', 'locations', 'teams', 'users'];
    protected array $search = ['name', 'code', 'campaign_type'];
    protected array $filters = ['status' => 'status', 'client_id' => 'client_id', 'campaign_type' => 'campaign_type'];
    protected array $sortable = ['name', 'code', 'start_date', 'end_date', 'status', 'created_at'];
    protected string $defaultSort = '-created_at';

    protected function rules(?Model $model = null): array
    {
        return [
            'client_id' => ['required', 'uuid', new Accessible(Client::class)],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-]+$/', Rule::unique('campaigns', 'code')->ignore($model?->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'campaign_type' => ['nullable', 'string', 'max:60'],
            'objectives' => ['nullable', 'string', 'max:5000'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    protected function creating(array $data, Request $request): array
    {
        $data = parent::creating($data, $request);
        $data['status'] = CampaignStatus::Draft->value;   // status only changes through the transition endpoint

        return $data;
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $campaign = $this->findScoped($request, $id);
        if ($campaign->status === CampaignStatus::Archived) {
            throw DomainRuleException::conflict('Archived campaigns cannot be edited.', 'campaign_archived');
        }
        if ($request->has('client_id') && $request->input('client_id') !== $campaign->client_id && $campaign->assignments()->exists()) {
            throw DomainRuleException::conflict('The client cannot change once assignments exist.', 'client_locked');
        }

        return parent::update($request, $id);
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
        $campaign = $this->findScoped($request, $id);
        $this->authorize('view', $campaign);
        $campaign->load([
            'activities:id,campaign_id,name,activity_type,status,target,form_id,start_date,end_date',
            'kpis', 'teams:id,name,supervisor_id', 'locations:id,name,town,county,status', 'products:id,name,sku',
            'forms:id,group_id,version,name,status', 'users:id,name',
        ]);

        return $this->ok($this->present($campaign));
    }

    protected function deleting(Model $model): void
    {
        if ($model->assignments()->exists()) {
            throw DomainRuleException::conflict('This campaign has assignments. Archive it instead of deleting it.', 'campaign_has_assignments');
        }
    }

    public function transition(Request $request, string $id, CampaignService $service): JsonResponse
    {
        $campaign = $this->findScoped($request, $id);
        $this->authorize('update', $campaign);
        $data = $request->validate([
            'status' => ['required', Rule::enum(CampaignStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $campaign = $service->transition($campaign, CampaignStatus::from($data['status']), $request->user(), $data['reason'] ?? null);

        return $this->ok($this->present($campaign->load('client:id,name')), 'Campaign status updated.');
    }

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        return $query;
    }
}
