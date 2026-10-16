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
    protected array $with = ['territory:id,name', 'client:id,name'];
    protected array $search = ['name', 'code', 'town', 'county', 'address'];
    protected array $filters = ['territory_id' => 'territory_id', 'client_id' => 'client_id', 'status' => 'status', 'county' => 'county', 'type' => 'type'];
    protected array $sortable = ['name', 'town', 'created_at'];
    protected string $defaultSort = 'name';

    public function __construct(private OutletService $outlets) {}

    protected function indexQuery(Builder $query, Request $request): Builder
    {
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
        if (isset($data['latitude'], $data['longitude'])) {
            $data += ['geo_source' => 'manual', 'geo_captured_at' => now(), 'geo_captured_by' => $request->user()->getKey(), 'needs_review' => false];
        }
        return $data;
    }

    protected function afterUpdate(Model $model, array $data, Request $request): void
    {
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
            'type' => ['nullable', Rule::in(['retail_store', 'supermarket', 'wholesale', 'kiosk', 'pharmacy', 'bar_restaurant', 'market', 'event_venue', 'other'])],
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
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('locations', 'code')->ignore($model?->getKey())],
            'type' => ['string', Rule::in(['retail_store', 'supermarket', 'wholesale', 'kiosk', 'pharmacy', 'bar_restaurant', 'market', 'event_venue', 'other'])],
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
