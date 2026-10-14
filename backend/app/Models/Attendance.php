<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasUuids;

    protected $table = 'attendance';

    protected $fillable = [
        'client_uuid',
        'user_id',
        'assignment_id',
        'attendance_date',
        'clock_in_at',
        'clock_in_lat',
        'clock_in_lng',
        'clock_in_accuracy_m',
        'clock_out_at',
        'clock_out_lat',
        'clock_out_lng',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }
}
