<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Domain\Projects\ProjectSettings;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasUuids, SoftDeletes, Auditable;

    protected $fillable = [
        'client_id',
        'code',
        'name',
        'description',
        'campaign_type',
        'objectives',
        'budget',
        'start_date',
        'end_date',
        'status',
        'status_reason',
        'created_by',
        'template_id',
        'settings',
    ];

    /** Always stored complete and validated (unknown keys dropped, gaps filled), whatever the caller sent. */
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => $v === null ? null : (is_array($v) ? $v : json_decode($v, true)),
            set: fn ($v) => $v === null ? null : json_encode(ProjectSettings::normalize(is_array($v) ? $v : json_decode((string) $v, true))),
        );
    }

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'budget' => 'decimal:2',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'template_id');
    }
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(CampaignKpi::class);
    }
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'campaign_teams', 'campaign_id', 'team_id');
    }
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'campaign_locations', 'campaign_id', 'location_id')->withPivot('visit_frequency');
    }
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'campaign_products', 'campaign_id', 'product_id');
    }
    public function forms(): BelongsToMany
    {
        return $this->belongsToMany(Form::class, 'campaign_forms', 'campaign_id', 'form_id');
    }
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'campaign_users', 'campaign_id', 'user_id')->withPivot('role_label');
    }
}
