<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchandisingRecord extends Model
{
    use HasUuids;

    protected $table = 'merchandising_records';

    protected $fillable = [
        'client_uuid',
        'visit_id',
        'product_id',
        'posm_present',
        'posm_type',
        'shelf_position',
        'planogram_compliant',
        'price_compliant',
        'competitor_present',
        'competitor_notes',
        'captured_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'posm_present' => 'boolean',
            'planogram_compliant' => 'boolean',
            'price_compliant' => 'boolean',
            'competitor_present' => 'boolean',
            'captured_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
