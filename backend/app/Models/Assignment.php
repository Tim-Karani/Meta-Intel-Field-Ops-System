<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use HasUuids, Auditable;

    protected $fillable = [
        'campaign_id',
        'activity_id',
        'agent_id',
        'location_id',
        'supervisor_id',
        'form_id',
        'scheduled_date',
        'start_time',
        'end_time',
        'instructions',
        'target',
        'status',
        'cancel_reason',
        'accepted_at',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
            'status' => AssignmentStatus::class,
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
