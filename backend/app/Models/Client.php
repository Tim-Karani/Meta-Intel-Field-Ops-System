<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasUuids, SoftDeletes, Auditable;

    protected $fillable = [
        'name',
        'code',
        'company_name',
        'industry',
        'email',
        'phone',
        'address',
        'status',
        'notes',
        'created_by',
    ];


    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }
    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class);
    }
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
