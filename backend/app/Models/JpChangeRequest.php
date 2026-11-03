<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JpChangeRequest extends Model
{
    use HasUuids;

    protected $fillable = ['client_uuid', 'journey_plan_id', 'agent_id', 'type', 'location_id', 'weekday', 'to_weekday', 'reason', 'latitude', 'longitude', 'accuracy_m',
        'proof_disk', 'proof_path', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(JourneyPlan::class, 'journey_plan_id');
    }
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
