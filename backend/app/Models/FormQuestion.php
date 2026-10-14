<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormQuestion extends Model
{
    use HasUuids;

    protected $fillable = [
        'form_id',
        'section_id',
        'type',
        'label',
        'help_text',
        'required',
        'evidence_required',
        'position',
        'validation',
        'scoring',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'evidence_required' => 'boolean',
            'validation' => 'array',
            'scoring' => 'array',
            'settings' => 'array',
            'type' => QuestionType::class,
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(FormSection::class, 'section_id');
    }
    public function options(): HasMany
    {
        return $this->hasMany(FormOption::class, 'question_id')->orderBy('position');
    }
    public function conditions(): HasMany
    {
        return $this->hasMany(FormCondition::class, 'question_id');
    }
}
