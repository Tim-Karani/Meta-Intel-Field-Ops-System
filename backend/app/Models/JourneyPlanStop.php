<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JourneyPlanStop extends Model
{
    use HasUuids;

    protected $fillable = ['journey_plan_id', 'location_id', 'activity_id', 'weekday', 'sequence', 'start_time'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(JourneyPlan::class, 'journey_plan_id');
    }
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
