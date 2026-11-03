<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;


class OutletStatus extends Model
{
    use HasUuids, Auditable;

    protected $fillable = ['name', 'tone', 'counts_as_active', 'is_default'];

    protected function casts(): array
    {
        return ['counts_as_active' => 'boolean', 'is_default' => 'boolean'];
    }

}
