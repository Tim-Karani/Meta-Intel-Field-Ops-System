<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfile extends Model
{
    use Auditable;

    protected $table = 'agent_profiles';

    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'agent_type',
        'employment_type',
        'can_add_outlets',
        'agent_code',
        'supervisor_id',
        'team_leader_id',
        'region',
        'pay_type',
        'pay_rate',
    ];


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
