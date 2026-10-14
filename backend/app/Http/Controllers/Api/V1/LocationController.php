<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Location;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
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
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
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
