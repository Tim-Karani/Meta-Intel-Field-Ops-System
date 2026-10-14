<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Territory extends Model
{
    use HasUuids, Auditable;

    protected $fillable = [
        'name',
        'code',
        'region',
        'description',
        'status',
    ];


    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }
}
