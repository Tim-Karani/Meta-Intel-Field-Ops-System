<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutletType extends Model
{
    use HasUuids, Auditable;

    protected $fillable = ['slug', 'name', 'status'];

    public function subtypes(): HasMany
    {
        return $this->hasMany(OutletSubtype::class);
    }
}
