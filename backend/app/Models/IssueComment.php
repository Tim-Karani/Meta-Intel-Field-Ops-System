<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueComment extends Model
{
    use HasUuids;

    protected $fillable = [
        'issue_id',
        'user_id',
        'type',
        'body',
    ];


    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
