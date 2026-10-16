<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasUuids, SoftDeletes, Auditable;

    protected $fillable = [
        'client_id',
        'territory_id',
        'name',
        'code',
        'type',
        'address',
        'county',
        'town',
        'latitude',
        'longitude',
        'geofence_radius_m',
        'contact_name',
        'contact_phone',
        'opening_hours',
        'status',
        'notes',
        'created_by',
        'geo_source',
        'geo_captured_at',
        'geo_captured_by',
        'geo_accuracy_m',
        'needs_review',
        'client_uuid',
        'created_via',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'geofence_radius_m' => 'integer',
            'geo_accuracy_m' => 'float',
            'geo_captured_at' => 'datetime',
            'needs_review' => 'boolean',
        ];
    }

    public function isMapped(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_locations', 'location_id', 'campaign_id');
    }
}
