<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A retail chain or trade group that outlets belong to; the unit of "by channel" reporting. */
class Channel extends Model
{
    use HasUuids, SoftDeletes, Auditable;

    protected $fillable = ['name', 'channel_group', 'code', 'contact_name', 'contact_phone', 'status', 'notes'];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
