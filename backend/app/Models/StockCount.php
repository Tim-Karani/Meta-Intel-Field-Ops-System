<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What is physically at the outlet for a product: on the shelf and in the back room. One per visit and product. */
class StockCount extends Model
{
    use HasUuids;

    protected $table = 'stock_counts';

    protected $fillable = ['client_uuid', 'visit_id', 'product_id', 'shelf_quantity', 'backroom_quantity', 'captured_at', 'notes'];

    protected $appends = ['total_quantity'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'shelf_quantity' => 'integer', 'backroom_quantity' => 'integer'];
    }

    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->shelf_quantity + (int) $this->backroom_quantity;
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
