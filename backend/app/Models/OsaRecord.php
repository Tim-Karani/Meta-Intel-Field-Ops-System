<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OsaRecord extends Model
{
    use HasUuids;

    protected $table = 'osa_records';

    protected $fillable = [
        'client_uuid',
        'visit_id',
        'product_id',
        'available',
        'out_of_stock',
        'shelf_quantity',
        'backroom_quantity',
        'facings',
        'share_of_shelf',
        'price_on_shelf',
        'display_compliant',
        'visibility_ok',
        'captured_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'out_of_stock' => 'boolean',
            'display_compliant' => 'boolean',
            'visibility_ok' => 'boolean',
            'captured_at' => 'datetime',
            'share_of_shelf' => 'decimal:2',
            'price_on_shelf' => 'decimal:2',
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
