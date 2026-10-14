<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasUuids;

    protected $table = 'sales';

    protected $fillable = [
        'client_uuid',
        'visit_id',
        'payment_method',
        'total_value',
        'captured_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'total_value' => 'decimal:2',
            'captured_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }
}
