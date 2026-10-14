<?php

namespace App\Models;

use App\Enums\GpsOutcome;
use App\Enums\VisitStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Visit extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_uuid',
        'assignment_id',
        'campaign_id',
        'activity_id',
        'agent_id',
        'location_id',
        'form_id',
        'status',
        'visit_date',
        'started_at',
        'completed_at',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'review_note',
        'gps_outcome',
        'device_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'gps_outcome' => GpsOutcome::class,
            'visit_date' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
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
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
    public function checkin(): HasOne
    {
        return $this->hasOne(VisitCheckin::class);
    }
    public function checkout(): HasOne
    {
        return $this->hasOne(VisitCheckout::class);
    }
    public function events(): HasMany
    {
        return $this->hasMany(VisitEvent::class)->orderBy('id');
    }
    public function responses(): HasMany
    {
        return $this->hasMany(VisitResponse::class);
    }
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
    public function osaRecords(): HasMany
    {
        return $this->hasMany(OsaRecord::class);
    }
    public function merchandisingRecords(): HasMany
    {
        return $this->hasMany(MerchandisingRecord::class);
    }
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }
}
