<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignKpi extends Model
{
    use HasUuids, Auditable;

    protected $fillable = [
        'campaign_id',
        'name',
        'metric',
        'target',
        'unit',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'decimal:2',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
