<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FormOption extends Model
{
    use HasUuids;

    protected $fillable = [
        'question_id',
        'label',
        'value',
        'score',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }
}
