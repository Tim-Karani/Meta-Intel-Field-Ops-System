<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasUuids;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'user_id',
        'entity',
        'client_uuid',
        'server_id',
        'payload_hash',
        'response',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
        ];
    }
}
