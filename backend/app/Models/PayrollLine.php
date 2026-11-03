<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    use HasUuids;

    protected $fillable = ['payroll_run_id', 'user_id', 'days_worked', 'hours_worked', 'late_days', 'outlets_visited', 'pay_type', 'pay_rate', 'gross', 'adjustment', 'adjustment_note', 'net'];

    protected function casts(): array
    {
        return ['hours_worked' => 'float', 'pay_rate' => 'float', 'gross' => 'float', 'adjustment' => 'float', 'net' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
