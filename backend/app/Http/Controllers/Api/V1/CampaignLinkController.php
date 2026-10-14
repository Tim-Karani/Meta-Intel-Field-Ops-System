<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Enums\AssignmentStatus;
use App\Models\Campaign;
use App\Models\Form;
use App\Models\Location;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Replaces the set of teams / locations / products / forms / users linked to a campaign.
 * PUT /campaigns/{id}/{relation}  with  { ids: [...] }  (locations also accept visit_frequency per item).
 */
class CampaignLinkController extends ApiController
{
    private const RELATIONS = ['teams', 'locations', 'products', 'forms', 'users'];

    public function sync(Request $request, string $id, string $relation): JsonResponse
    {
        abort_unless(in_array($relation, self::RELATIONS, true), 404);
        $campaign = $this->campaign($request, $id);
        $this->authorize('update', $campaign);
        abort_unless($request->user()->hasPermission('campaigns.assign'), 403, 'You are not allowed to link resources to campaigns.');

        $before = DB::table($this->table($relation))->where('campaign_id', $campaign->id)->pluck($this->column($relation))->sort()->values()->all();
        $after = DB::transaction(fn () => $this->{'sync'.ucfirst($relation)}($request, $campaign));
        sort($after);

        AuditService::record("campaign.{$relation}_synced", 'campaigns', $campaign, [$relation => $before], [$relation => $after]);

        return $this->ok($campaign->fresh()->load($relation)->$relation, ucfirst($relation).' updated.');
    }

    public function show(Request $request, string $id, string $relation): JsonResponse
    {
        abort_unless(in_array($relation, self::RELATIONS, true), 404);
        $campaign = $this->campaign($request, $id);
        $this->authorize('view', $campaign);

        return $this->ok($campaign->load($relation)->$relation);
    }

    private function campaign(Request $request, string $id): Campaign
    {
        $this->assertUuid($id);

        return \App\Domain\Access\AccessScope::scope(Campaign::query(), $request->user())->findOrFail($id);
    }

    private function ids(Request $request, string $model, ?\Closure $extra = null, string $key = 'ids'): array
    {
        $request->validate([
            $key => ['present', 'array', 'max:2000'],
            "$key.*" => ['uuid', 'distinct', new Accessible($model, $extra)],
        ]);

        return array_values($request->input($key));
    }

    private function table(string $relation): string
    {
        return ['teams' => 'campaign_teams', 'locations' => 'campaign_locations', 'products' => 'campaign_products', 'forms' => 'campaign_forms', 'users' => 'campaign_users'][$relation];
    }

    private function column(string $relation): string
    {
        return ['teams' => 'team_id', 'locations' => 'location_id', 'products' => 'product_id', 'forms' => 'form_id', 'users' => 'user_id'][$relation];
    }

    private function syncTeams(Request $request, Campaign $campaign): array
    {
        $ids = $this->ids($request, Team::class);
        $campaign->teams()->sync($ids);

        return $ids;
    }

    private function syncLocations(Request $request, Campaign $campaign): array
    {
        $request->validate([
            'locations' => ['required_without:ids', 'array', 'max:5000'],
            'locations.*.id' => ['required_with:locations', 'uuid', 'distinct', new Accessible(Location::class)],
            'locations.*.visit_frequency' => ['nullable', Rule::in(['once', 'daily', 'weekly', 'biweekly', 'monthly'])],
            'ids' => ['array', 'max:5000'],
            'ids.*' => ['uuid', 'distinct', new Accessible(Location::class)],
        ]);
        $items = $request->has('locations')
            ? collect($request->input('locations'))->mapWithKeys(fn ($l) => [$l['id'] => ['visit_frequency' => $l['visit_frequency'] ?? null]])
            : collect($request->input('ids', []))->mapWithKeys(fn ($i) => [$i => ['visit_frequency' => null]]);

        // A location with open work cannot be unlinked.
        $removed = DB::table('campaign_locations')->where('campaign_id', $campaign->id)->whereNotIn('location_id', $items->keys())->pluck('location_id');
        if ($removed->isNotEmpty()) {
            $open = DB::table('assignments')->where('campaign_id', $campaign->id)->whereIn('location_id', $removed)
                ->whereIn('status', array_map(fn ($s) => $s->value, array_filter(AssignmentStatus::cases(), fn ($s) => $s->isOpen())))->count();
            if ($open > 0) {
                throw DomainRuleException::conflict("Cannot remove locations that still have {$open} open assignment(s). Cancel them first.", 'locations_in_use');
            }
        }
        $campaign->locations()->sync($items->all());

        return $items->keys()->all();
    }

    private function syncProducts(Request $request, Campaign $campaign): array
    {
        $ids = $this->ids($request, Product::class);
        // A product belongs either to the campaign's client or to nobody (shared catalogue).
        $bad = Product::query()->whereIn('id', $ids)->whereNotNull('client_id')->where('client_id', '!=', $campaign->client_id)->exists();
        if ($bad) {
            throw new DomainRuleException('Products of another client cannot be linked to this campaign.', 'product_client_mismatch');
        }
        $campaign->products()->sync($ids);

        return $ids;
    }

    private function syncForms(Request $request, Campaign $campaign): array
    {
        $ids = $this->ids($request, Form::class, fn ($q) => $q->where('status', 'published'));
        $campaign->forms()->sync($ids);

        return $ids;
    }

    private function syncUsers(Request $request, Campaign $campaign): array
    {
        $ids = $this->ids($request, User::class, fn ($q) => $q->where('users.status', 'active')->whereNull('users.client_id'));
        $campaign->users()->sync($ids);

        return $ids;
    }
}
