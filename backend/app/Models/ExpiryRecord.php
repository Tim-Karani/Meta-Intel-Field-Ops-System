<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stock found at an outlet that expires on a date: how many, and which product. */
class ExpiryRecord extends Model
{
    use HasUuids;

    protected $table = 'expiry_records';

    protected $fillable = ['client_uuid', 'visit_id', 'product_id', 'expiry_date', 'quantity', 'batch_no', 'captured_at', 'notes'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date:Y-m-d', 'captured_at' => 'datetime', 'quantity' => 'integer'];
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
