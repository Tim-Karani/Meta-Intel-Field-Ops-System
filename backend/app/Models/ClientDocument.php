<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientDocument extends Model
{
    use HasUuids, Auditable;

    protected $fillable = [
        'client_id',
        'title',
        'disk',
        'path',
        'mime',
        'size_bytes',
        'uploaded_by',
    ];

    protected $hidden = ['disk', 'path'];


    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
