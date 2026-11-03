<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;


class OutletSubtype extends Model
{
    use HasUuids, Auditable;

    protected $fillable = ['outlet_type_id', 'name', 'status'];

    public function type(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OutletType::class, 'outlet_type_id');
    }
}
