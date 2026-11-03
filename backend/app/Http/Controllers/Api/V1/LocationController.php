<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Locations\OutletService;
use App\Models\Location;
use App\Models\Campaign;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LocationController extends ResourceController
{
    protected string $model = Location::class;
    protected array $with = ['territory:id,name', 'client:id,name', 'channel:id,name,channel_group', 'outletSubtype:id,name,outlet_type_id', 'outletStatus:id,name,tone,counts_as_active', 'distributor:id,name', 'geoArea:id,name,level,parent_id'];
    protected array $search = ['name', 'code', 'town', 'county', 'address', 'region', 'locality', 'phone', 'shop_no', 'market_name'];
    protected array $filters = ['territory_id' => 'territory_id', 'status' => 'status', 'county' => 'county', 'type' => 'type', 'channel_id' => 'channel_id', 'region' => 'region', 'outlet_subtype_id' => 'outlet_subtype_id', 'outlet_status_id' => 'outlet_status_id', 'distributor_id' => 'distributor_id', 'setting' => 'setting'];
    protected array $sortable = ['name', 'town', 'created_at'];
    protected string $defaultSort = 'name';

    public function __construct(private OutletService $outlets) {}

    protected function indexQuery(Builder $query, Request $request): Builder
    {
        if (is_string($geo = $request->query('geo_area_id')) && \Illuminate\Support\Str::isUuid($geo)) {
            $query->whereIn('locations.geo_area_id', $this->areaWithDescendants($geo));
        }

        return match ($request->query('geo')) {
            'unmapped' => $query->whereNull('locations.latitude'),
            'review' => $query->where('locations.needs_review', true),
            'mapped' => $query->whereNotNull('locations.latitude'),
            default => $query,
        };
    }

    protected function creating(array $data, Request $request): array
    {
        $data = parent::creating($data, $request);
        $data = array_merge($data, $this->derived($data, null));
        if (isset($data['latitude'], $data['longitude'])) {
            $data += ['geo_source' => 'manual', 'geo_captured_at' => now(), 'geo_captured_by' => $request->user()->getKey(), 'needs_review' => false];
        }
        return $data;
    }

    protected function afterUpdate(Model $model, array $data, Request $request): void
    {
        $typeChanged = $model->wasChanged('type');
        if (array_key_exists('outlet_status_id', $data) || array_key_exists('geo_area_id', $data)) {
            $model->forceFill($this->derived($data, $model))->save();
        }
        if ($typeChanged && !array_key_exists('outlet_subtype_id', $data)) {
            $model->forceFill(['outlet_subtype_id' => null])->save();   // a sub-type belongs to one type
        }
        if (!array_key_exists('latitude', $data) && !array_key_exists('longitude', $data)) {
            return;
        }
        if ($model->wasChanged(['latitude', 'longitude'])) {
            $mapped = $model->latitude !== null && $model->longitude !== null;
            $model->forceFill($mapped
                ? ['geo_source' => 'manual', 'geo_captured_at' => now(), 'geo_captured_by' => $request->user()->getKey(), 'geo_accuracy_m' => null, 'needs_review' => false]
                : ['geo_source' => null, 'geo_captured_at' => null, 'geo_captured_by' => null, 'geo_accuracy_m' => null])->save();
        }
    }

    /**
     * What the saved row should also carry: the active/inactive flag that follows the chosen status (new outlets get the
     * default status), and the region / locality / town text that follows the chosen place in the geography.
     */
    private function derived(array $data, ?Location $loc): array
    {
        $out = [];
        if (!$loc && empty($data['outlet_status_id'])) {
            $wantActive = ($data['status'] ?? 'active') !== 'inactive';
            $status = \App\Models\OutletStatus::query()->where('counts_as_active', $wantActive)->orderByDesc('is_default')->first();
        } elseif (!empty($data['outlet_status_id'])) {
            $status = \App\Models\OutletStatus::query()->find($data['outlet_status_id']);
        }
        if (!empty($status)) {
            $out['outlet_status_id'] = $status->id;
            $out['status'] = $status->counts_as_active ? 'active' : 'inactive';
        }
        if (!empty($data['geo_area_id'])) {
            $area = \App\Models\GeoArea::query()->find($data['geo_area_id']);
            for ($i = 0; $area && $i < 5; $i++, $area = $area->parent_id ? \App\Models\GeoArea::query()->find($area->parent_id) : null) {
                match ($area->level) {
                    'region' => $out['region'] = $area->name,
                    'locality' => $out['locality'] = $area->name,
                    'area' => $out['town'] = $data['town'] ?? $area->name,
                    default => null,
                };
            }
        }

        return $out;
    }

    /** The place and everything below it (country > region > locality > area). */
    private function areaWithDescendants(string $id): array
    {
        $ids = [$id];
        $layer = [$id];
        for ($i = 0; $i < 3 && $layer; $i++) {
            $layer = \App\Models\GeoArea::query()->whereIn('parent_id', $layer)->pluck('id')->all();
            $ids = array_merge($ids, $layer);
        }

        return $ids;
    }

    /**
     * POST /locations/batch — change one thing about many outlets at once (up to 500). Only the fields sent are changed;
     * send null to clear a field. Every outlet must be one the user may edit.
     */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['uuid', new Accessible(Location::class)],
            'changes' => ['required', 'array'],
            'changes.channel_id' => ['nullable', 'uuid', new Accessible(\App\Models\Channel::class)],
            'changes.type' => ['string', Rule::exists('outlet_types', 'slug')],
            'changes.outlet_subtype_id' => ['nullable', 'uuid', Rule::exists('outlet_subtypes', 'id')],
            'changes.outlet_status_id' => ['uuid', Rule::exists('outlet_statuses', 'id')],
            'changes.distributor_id' => ['nullable', 'uuid', new Accessible(\App\Models\Distributor::class)],
            'changes.geo_area_id' => ['nullable', 'uuid', new Accessible(\App\Models\GeoArea::class)],
            'changes.territory_id' => ['nullable', 'uuid', new Accessible(\App\Models\Territory::class)],
            'changes.setting' => ['nullable', 'in:urban,peri_urban,rural'],
        ]);
        $changes = array_intersect_key($data['changes'] ?? [], array_flip(['channel_id', 'type', 'outlet_subtype_id', 'outlet_status_id', 'distributor_id', 'geo_area_id', 'territory_id', 'setting']));
        if ($changes === []) {
            throw new DomainRuleException('Choose at least one thing to change.', 'nothing_to_change');
        }

        $count = DB::transaction(function () use ($data, $changes) {
            $n = 0;
            foreach (Location::query()->whereIn('id', $data['ids'])->get() as $loc) {
                $set = $changes;
                $typeAfter = $changes['type'] ?? $loc->type;
                if (isset($changes['outlet_subtype_id']) && !\App\Models\OutletSubtype::query()->whereKey($changes['outlet_subtype_id'])->whereHas('type', fn ($q) => $q->where('slug', $typeAfter))->exists()) {
                    throw new DomainRuleException('That sub-type belongs to a different outlet type.', 'subtype_type_mismatch');
                }
                if (isset($changes['type']) && $changes['type'] !== $loc->type && !array_key_exists('outlet_subtype_id', $changes)) {
                    $set['outlet_subtype_id'] = null;
                }
                $loc->forceFill($set + $this->derived($changes, $loc))->save();
                $n++;
            }

            return $n;
        });
        \App\Domain\Audit\AuditService::record('locations.batch_updated', 'locations', null, null, ['count' => $count, 'fields' => array_keys($changes)]);

        return $this->ok(['updated' => $count], "Updated $count outlet(s).");
    }

    /** GET /locations/summary?group_by=channel|type|status|region|distributor — outlet counts for the overview. */
    public function summary(Request $request): JsonResponse
    {
        $by = $request->validate(['group_by' => ['nullable', 'in:channel,type,status,region,distributor']])['group_by'] ?? 'channel';
        $base = \App\Domain\Access\ProjectFocus::restrict(\App\Domain\Access\AccessScope::scope(Location::query(), $request->user()), 'locations', $request->query());
        $ids = $base->select('locations.id');
        $q = DB::table('locations as l')->whereIn('l.id', $ids)->whereNull('l.deleted_at');
        [$q, $key, $name, $extra] = match ($by) {
            'type' => [$q->leftJoin('outlet_types as g', 'g.slug', '=', 'l.type'), 'g.id', "coalesce(g.name, 'No type')", null],
            'status' => [$q->leftJoin('outlet_statuses as g', 'g.id', '=', 'l.outlet_status_id'), 'g.id', "coalesce(g.name, 'No status')", 'g.tone'],
            'region' => [$q, 'l.region', "coalesce(l.region, 'No region')", null],
            'distributor' => [$q->leftJoin('distributors as g', 'g.id', '=', 'l.distributor_id'), 'g.id', "coalesce(g.name, 'No distributor')", null],
            default => [$q->leftJoin('channels as g', 'g.id', '=', 'l.channel_id'), 'g.id', "coalesce(g.name, 'Unassigned')", 'g.channel_group'],
        };
        $rows = $q->groupBy(DB::raw($key), DB::raw($name), ...($extra ? [DB::raw($extra)] : []))->orderByRaw($name)
            ->selectRaw("$key as k, $name as name, ".($extra ? "$extra as extra, " : "null as extra, ").'count(*) as total, count(*) filter (where l.latitude is not null) as mapped, count(*) filter (where l.needs_review) as needs_review, count(*) filter (where l.status = \'active\') as active')
            ->get()->map(fn ($r) => ['key' => $r->k, 'name' => $r->name, 'extra' => $r->extra, 'total' => (int) $r->total, 'mapped' => (int) $r->mapped, 'needs_review' => (int) $r->needs_review, 'active' => (int) $r->active])->all();

        return $this->ok($rows);
    }

    /** POST /locations/field — a field agent adds the outlet they are standing at (position captured at check-in). */
    public function storeInField(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'location_id' => ['required', 'uuid'],
            'assignment_id' => ['nullable', 'uuid'],
            'campaign_id' => ['required', 'uuid', new Accessible(Campaign::class)],
            'activity_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'type' => ['nullable', Rule::exists('outlet_types', 'slug')],
            'address' => ['nullable', 'string', 'max:1000'],
            'county' => ['nullable', 'string', 'max:80'],
            'town' => ['nullable', 'string', 'max:80'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->outlets->createInField($data, $user);
        $payload = [
            'location' => $result['location']->only(['id', 'name', 'type', 'address', 'town', 'county', 'latitude', 'longitude', 'geofence_radius_m', 'contact_name', 'contact_phone', 'needs_review', 'created_via']),
            'assignment' => $result['assignment']?->only(['id', 'campaign_id', 'activity_id', 'location_id', 'form_id', 'status', 'scheduled_date']),
        ];

        return $result['created'] ? ApiResponse::created($payload) : $this->ok($payload, 'Outlet already received.');
    }

    /**
     * POST /locations/import — add many outlets at once (up to 500), optionally linking them all to a campaign.
     * Each row is checked on its own: good rows are saved, bad rows are listed with the reason and nothing else is affected.
     * A row whose code (or name + town) already exists is not duplicated; the existing outlet is just linked to the campaign.
     * With dry_run=true nothing is saved, so the office can preview the result.
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'campaign_id' => ['nullable', 'uuid', new Accessible(Campaign::class)],
            'client_id' => ['nullable', 'uuid', new Accessible(\App\Models\Client::class)],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        $campaign = !empty($data['campaign_id']) ? Campaign::query()->findOrFail($data['campaign_id']) : null;
        if ($campaign && $campaign->status->value === 'archived') {
            throw new DomainRuleException('Archived campaigns are read-only.', 'campaign_archived');
        }
        $dry = (bool) ($data['dry_run'] ?? false);
        $types = \App\Models\OutletType::query()->pluck('slug')->all();
        $typeNames = \App\Models\OutletType::query()->pluck('slug', 'name')->mapWithKeys(fn ($slug, $name) => [mb_strtolower($name) => $slug])->all();
        $user = $request->user();

        $created = $linked = 0;
        $errors = [];
        $seen = [];
        $ids = [];
        $channels = [];
        $lookups = [];

        DB::transaction(function () use ($data, $types, $typeNames, $user, $dry, &$created, &$linked, &$errors, &$seen, &$ids, &$channels, &$lookups) {
            foreach ($data['rows'] as $i => $row) {
                $n = $i + 1;
                $row = is_array($row) ? array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row) : [];
                $row = array_filter($row, fn ($v) => $v !== null && $v !== '');
                // The type may be given by name ("Bar / restaurant") or by its short code.
                if (isset($row['type']) && !in_array($row['type'], $types, true) && isset($typeNames[mb_strtolower($row['type'])])) {
                    $row['type'] = $typeNames[mb_strtolower($row['type'])];
                }
                $v = validator($row, [
                    'name' => ['required', 'string', 'max:255'],
                    'code' => ['nullable', 'string', 'max:40'],
                    'type' => ['nullable', Rule::in($types)],
                    'address' => ['nullable', 'string', 'max:1000'],
                    'county' => ['nullable', 'string', 'max:80'],
                    'town' => ['nullable', 'string', 'max:80'],
                    'channel' => ['nullable', 'string', 'max:255'],
                    'channel_group' => ['nullable', 'string', 'max:80'],
                    'country' => ['nullable', 'string', 'max:120'],
                    'region' => ['nullable', 'string', 'max:80'],
                    'locality' => ['nullable', 'string', 'max:80'],
                    'area' => ['nullable', 'string', 'max:120'],
                    'subtype' => ['nullable', 'string', 'max:120'],
                    'distributor' => ['nullable', 'string', 'max:255'],
                    'status' => ['nullable', 'string', 'max:80'],
                    'phone' => ['nullable', 'string', 'max:40'],
                    'email' => ['nullable', 'email', 'max:255'],
                    'shop_no' => ['nullable', 'string', 'max:60'],
                    'market_name' => ['nullable', 'string', 'max:255'],
                    'setting' => ['nullable', 'in:urban,peri_urban,rural'],
                    'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
                    'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
                    'geofence_radius_m' => ['nullable', 'integer', 'between:10,5000'],
                    'contact_name' => ['nullable', 'string', 'max:255'],
                    'contact_phone' => ['nullable', 'string', 'max:40'],
                    'opening_hours' => ['nullable', 'string', 'max:255'],
                    'notes' => ['nullable', 'string', 'max:2000'],
                    'territory' => ['nullable', 'string', 'max:255'],
                ]);
                $territoryId = null;
                $problems = $v->fails() ? $v->errors()->all() : [];
                if (!$problems && isset($row['territory'])) {
                    $territoryId = \App\Models\Territory::query()->whereRaw('lower(name) = ?', [mb_strtolower($row['territory'])])->value('id');
                    if (!$territoryId) {
                        $problems[] = "Territory '{$row['territory']}' does not exist. Create it first or leave it empty.";
                    }
                }
                if ($problems) {
                    $errors[] = ['row' => $n, 'name' => $row['name'] ?? null, 'messages' => $problems];
                    continue;
                }
                $key = mb_strtolower(($row['code'] ?? '') !== '' ? 'c:'.$row['code'] : 'n:'.$row['name'].'|'.($row['town'] ?? ''));
                if (isset($seen[$key])) {
                    $errors[] = ['row' => $n, 'name' => $row['name'], 'messages' => ["Same outlet as row {$seen[$key]} in this file."]];
                    continue;
                }
                $seen[$key] = $n;

                $place = $this->importPlace($row, $dry, $lookups);
                if (is_string($place)) {
                    $errors[] = ['row' => $n, 'name' => $row['name'], 'messages' => [$place]];
                    continue;
                }
                $existing = Location::query()
                    ->when(isset($row['code']), fn ($q) => $q->where('code', $row['code']),
                        fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($row['name'])])->whereRaw("lower(coalesce(town, '')) = ?", [mb_strtolower($row['town'] ?? '')]))
                    ->first();
                if ($existing) {
                    $ids[] = $existing->id;
                    $linked++;
                    continue;
                }
                $created++;
                if ($dry) {
                    continue;
                }
                $mapped = isset($row['latitude'], $row['longitude']);
                $extra = $this->importExtras($row, $lookups);
                $channelId = isset($row['channel']) ? $this->channelByName($row['channel'], $row['channel_group'] ?? null, $channels)?->id : null;
                $loc = Location::query()->create($extra + $place + [
                    'channel_id' => $channelId,
                    'territory_id' => $territoryId, 'opening_hours' => $row['opening_hours'] ?? null, 'notes' => $row['notes'] ?? null,
                    'client_id' => $data['client_id'] ?? null, 'name' => $row['name'], 'code' => $row['code'] ?? null, 'type' => $row['type'] ?? 'retail_store',
                    'address' => $row['address'] ?? null, 'county' => $row['county'] ?? null, 'town' => $row['town'] ?? null,
                    'latitude' => $mapped ? (float) $row['latitude'] : null, 'longitude' => $mapped ? (float) $row['longitude'] : null,
                    'geofence_radius_m' => (int) ($row['geofence_radius_m'] ?? 100), 'contact_name' => $row['contact_name'] ?? null, 'contact_phone' => $row['contact_phone'] ?? null,
                    'status' => isset($extra['outlet_status_id']) && !\App\Models\OutletStatus::query()->whereKey($extra['outlet_status_id'])->value('counts_as_active') ? 'inactive' : 'active', 'created_by' => $user->getKey(), 'created_via' => 'office',
                ] + ($mapped ? ['geo_source' => 'manual', 'geo_captured_at' => now(), 'geo_captured_by' => $user->getKey(), 'needs_review' => false] : []));
                $ids[] = $loc->id;
            }
        });

        if (!$dry && $campaign && $ids) {
            $campaign->locations()->syncWithoutDetaching($ids);
        }
        if (!$dry) {
            \App\Domain\Audit\AuditService::record('locations.imported', 'locations', null, null, ['created' => $created, 'linked_existing' => $linked, 'rejected' => count($errors), 'campaign_id' => $campaign?->id]);
        }

        return $this->ok([
            'dry_run' => $dry, 'created' => $created, 'linked_existing' => $linked, 'rejected' => count($errors), 'errors' => $errors,
            'linked_to_campaign' => !$dry && $campaign ? count($ids) : 0,
        ], $dry ? 'Preview only: nothing was saved.' : "Imported $created new outlet(s).");
    }

    /** Resolves country > region > locality > area names to a place in the geography (created when new). Returns attributes, or an error string. */
    private function importPlace(array $row, bool $dry, array &$cache): array|string
    {
        $levels = array_filter(['country' => $row['country'] ?? null, 'region' => $row['region'] ?? null, 'locality' => $row['locality'] ?? null, 'area' => $row['area'] ?? null]);
        if (!$levels) {
            return [];
        }
        if (!isset($levels['region']) && (isset($levels['locality']) || isset($levels['area']))) {
            return 'Give the region as well when you give a locality or area.';
        }
        if (!isset($levels['locality']) && isset($levels['area'])) {
            return 'Give the locality as well when you give an area.';
        }
        $levels += isset($levels['region']) ? ['country' => config('field.default_country', 'Kenya')] : [];
        $parent = null;
        $out = [];
        foreach (['country', 'region', 'locality', 'area'] as $level) {
            if (!isset($levels[$level])) {
                continue;
            }
            $key = $level.'|'.($parent ?? '').'|'.mb_strtolower($levels[$level]);
            if (!array_key_exists($key, $cache)) {
                $found = \App\Models\GeoArea::query()->where('level', $level)->where('parent_id', $parent)->whereRaw('lower(name) = ?', [mb_strtolower($levels[$level])])->first();
                $cache[$key] = $found?->id ?? ($dry ? false : \App\Models\GeoArea::query()->create(['level' => $level, 'parent_id' => $parent, 'name' => $levels[$level], 'status' => 'active'])->id);
            }
            if ($cache[$key] === false) {
                return [];   // a preview: it would be created, nothing to link yet
            }
            $parent = $cache[$key];
        }
        $out['geo_area_id'] = $parent;
        $out['region'] = $levels['region'] ?? null;
        $out['locality'] = $levels['locality'] ?? null;
        if (isset($levels['area']) && empty($row['town'])) {
            $out['town'] = $levels['area'];
        }

        return $out;
    }

    /** Sub-type, distributor, status and the plain trade details from an import row. */
    private function importExtras(array $row, array &$cache): array
    {
        $out = array_intersect_key($row, array_flip(['phone', 'email', 'shop_no', 'market_name', 'setting']));
        $type = $row['type'] ?? 'retail_store';
        if (isset($row['subtype'])) {
            $typeId = \App\Models\OutletType::query()->where('slug', $type)->value('id');
            $sub = \App\Models\OutletSubtype::query()->where('outlet_type_id', $typeId)->whereRaw('lower(name) = ?', [mb_strtolower($row['subtype'])])->first()
                ?? \App\Models\OutletSubtype::query()->create(['outlet_type_id' => $typeId, 'name' => $row['subtype'], 'status' => 'active']);
            $out['outlet_subtype_id'] = $sub->id;
        }
        if (isset($row['distributor'])) {
            $key = 'dist|'.mb_strtolower($row['distributor']);
            $cache[$key] ??= (\App\Models\Distributor::query()->whereRaw('lower(name) = ?', [mb_strtolower($row['distributor'])])->first()
                ?? \App\Models\Distributor::query()->create(['name' => $row['distributor'], 'status' => 'active']))->id;
            $out['distributor_id'] = $cache[$key];
        }
        $status = isset($row['status']) ? \App\Models\OutletStatus::query()->whereRaw('lower(name) = ?', [mb_strtolower($row['status'])])->first() : null;
        $status ??= \App\Models\OutletStatus::query()->where('is_default', true)->first();
        if ($status) {
            $out['outlet_status_id'] = $status->id;
        }

        return $out;
    }

    /** Finds a channel by name (case-insensitive) or creates it; cached per import so a chain is created once. */
    private function channelByName(string $name, ?string $group, array &$cache): \App\Models\Channel
    {
        $key = mb_strtolower(trim($name));

        return $cache[$key] ??= \App\Models\Channel::query()->whereRaw('lower(name) = ?', [$key])->first()
            ?? \App\Models\Channel::query()->create(['name' => trim($name), 'channel_group' => $group, 'status' => 'active']);
    }

    /** POST /locations/{id}/confirm-geo — a reviewer accepts (optionally corrects) the captured position. */
    public function confirmGeo(Request $request, string $id): JsonResponse
    {
        $location = $this->findScoped($request, $id);
        $this->authorize('update', $location);
        $data = $request->validate([
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ]);

        $location = $this->outlets->confirm($location, $request->user(), isset($data['latitude']) ? (float) $data['latitude'] : null, isset($data['longitude']) ? (float) $data['longitude'] : null);

        return $this->ok($this->present($location->load($this->with)), 'Outlet position confirmed.');
    }

    protected function rules(?Model $model = null): array
    {
        return [
            'client_id' => ['nullable', 'uuid', new Accessible(\App\Models\Client::class)],
            'territory_id' => ['nullable', 'uuid', new Accessible(\App\Models\Territory::class)],
            'channel_id' => ['nullable', 'uuid', new Accessible(\App\Models\Channel::class)],
            'region' => ['nullable', 'string', 'max:80'],
            'locality' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('locations', 'code')->ignore($model?->getKey())],
            'type' => ['string', Rule::exists('outlet_types', 'slug')],
            'outlet_subtype_id' => ['nullable', 'uuid', Rule::exists('outlet_subtypes', 'id'), function ($attr, $value, $fail) use ($model) {
                $type = request()->input('type', $model?->type);
                $ok = $value === null || \App\Models\OutletSubtype::query()->whereKey($value)->whereHas('type', fn ($q) => $q->where('slug', $type))->exists();
                $ok || $fail('That sub-type belongs to a different outlet type.');
            }],
            'outlet_status_id' => ['nullable', 'uuid', Rule::exists('outlet_statuses', 'id')],
            'distributor_id' => ['nullable', 'uuid', new Accessible(\App\Models\Distributor::class)],
            'geo_area_id' => ['nullable', 'uuid', new Accessible(\App\Models\GeoArea::class)],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'shop_no' => ['nullable', 'string', 'max:60'],
            'market_name' => ['nullable', 'string', 'max:255'],
            'setting' => ['nullable', 'in:urban,peri_urban,rural'],
            'address' => ['nullable', 'string', 'max:1000'],
            'county' => ['nullable', 'string', 'max:80'],
            'town' => ['nullable', 'string', 'max:80'],
            // Optional: an outlet without coordinates is mapped from the first check-in (see OutletService).
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'geofence_radius_m' => ['integer', 'between:10,5000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'opening_hours' => ['nullable', 'string', 'max:255'],
            'status' => ['in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function deleting(Model $model): void
    {
        if (DB::table('assignments')->where('location_id', $model->getKey())->exists()) {
            throw DomainRuleException::conflict('This location has assignments. Mark it inactive instead of deleting it.', 'location_in_use');
        }
    }
}
