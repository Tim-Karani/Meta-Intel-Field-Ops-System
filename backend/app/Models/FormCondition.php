<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FormCondition extends Model
{
    use HasUuids;

    protected $fillable = [
        'question_id',
        'depends_on_question_id',
        'operator',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
