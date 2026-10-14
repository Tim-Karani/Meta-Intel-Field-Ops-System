<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Form extends Model
{
    use HasUuids, SoftDeletes, Auditable;

    protected $fillable = [
        'group_id',
        'version',
        'name',
        'description',
        'status',
        'requires_live_capture',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'requires_live_capture' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(FormSection::class)->orderBy('position');
    }
    public function questions(): HasMany
    {
        return $this->hasMany(FormQuestion::class)->orderBy('position');
    }
}
