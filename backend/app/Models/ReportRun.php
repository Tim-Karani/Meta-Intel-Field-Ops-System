<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['schedule_id', 'status', 'row_count', 'recipient_count', 'error', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
