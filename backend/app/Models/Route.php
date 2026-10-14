<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Route extends Model
{
    use HasUuids, Auditable;

    protected $table = 'routes';

    protected $fillable = [
        'territory_id',
        'name',
        'code',
        'description',
        'status',
    ];


    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'route_locations', 'route_id', 'location_id')->withPivot('position')->orderBy('position');
    }
}
