<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Domain\Projects\ProjectSettings;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ProjectTemplate extends Model
{
    use HasUuids, Auditable;

    protected $fillable = ['slug', 'name', 'description', 'is_system', 'settings', 'created_by'];

    /** Always stored complete and validated (unknown keys dropped, gaps filled), whatever the caller sent. */
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => $v === null ? null : (is_array($v) ? $v : json_decode($v, true)),
            set: fn ($v) => $v === null ? null : json_encode(ProjectSettings::normalize(is_array($v) ? $v : json_decode((string) $v, true))),
        );
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
