<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A report that is built and emailed automatically. It is always built with its OWNER's access, never wider. */
class ReportSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'report_type', 'filters', 'period', 'frequency', 'weekday', 'month_day', 'send_time',
        'recipients', 'is_active', 'owner_id', 'last_run_at', 'last_status', 'last_error',
    ];

    protected function casts(): array
    {
        return ['filters' => 'array', 'recipients' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class, 'schedule_id')->latest('created_at');
    }
}
