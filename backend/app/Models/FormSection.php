<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormSection extends Model
{
    use HasUuids;

    protected $fillable = [
        'form_id',
        'title',
        'description',
        'position',
    ];


    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
    public function questions(): HasMany
    {
        return $this->hasMany(FormQuestion::class, 'section_id')->orderBy('position');
    }
}
