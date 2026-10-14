<?php

namespace App\Models;

use App\Enums\GpsOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitCheckin extends Model
{
    use HasUuids;

    protected $fillable = [
        'visit_id',
        'latitude',
        'longitude',
        'accuracy_m',
        'device_ts',
        'server_ts',
        'distance_m',
        'outcome',
        'reasons',
        'mock_flag',
        'provider',
        'device_id',
        'network_type',
        'app_version',
    ];

    protected $hidden = ['device_id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'float',
            'distance_m' => 'float',
            'device_ts' => 'datetime',
            'server_ts' => 'datetime',
            'reasons' => 'array',
            'mock_flag' => 'boolean',
            'outcome' => GpsOutcome::class,
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
