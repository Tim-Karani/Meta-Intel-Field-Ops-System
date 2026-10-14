<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GpsRule extends Model
{
    use HasUuids, Auditable;

    protected $table = 'gps_rules';

    protected $fillable = [
        'campaign_id',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }
}
