<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stock handed to a field agent (issue), handed back (return) or corrected (adjust). Sales reduce what they should still hold. */
class StockMovement extends Model
{
    use HasUuids;

    protected $fillable = ['campaign_id', 'agent_id', 'product_id', 'kind', 'quantity', 'note', 'created_by'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
