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
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'geofence_radius_m' => 'integer',
        ];
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
