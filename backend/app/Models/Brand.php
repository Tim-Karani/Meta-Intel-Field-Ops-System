<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Brand extends Model
{
    use HasUuids, Auditable;

    protected $fillable = [
        'client_id',
        'name',
    ];


    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
