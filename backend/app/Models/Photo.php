<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_uuid',
        'visit_id',
        'question_id',
        'taken_by',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'width',
        'height',
        'sha256',
        'captured_at',
        'latitude',
        'longitude',
        'review_status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}
