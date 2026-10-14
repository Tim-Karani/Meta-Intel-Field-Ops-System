<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\Role as RoleEnum;
use App\Enums\UserStatus;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements CanResetPasswordContract
{
    use HasApiTokens, HasUuids, SoftDeletes, Notifiable, CanResetPassword, Auditable;

    /** Scope breadth, widest first. */
    private const SCOPE_RANK = ['all' => 5, 'campaigns' => 4, 'team' => 3, 'client' => 2, 'own' => 1];

    protected $fillable = ['name', 'email', 'username', 'phone', 'password', 'status', 'client_id', 'must_change_password'];

    protected $hidden = ['password', 'remember_token', 'failed_logins', 'locked_until', 'last_login_ip'];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class, 'user_id');
    }

    /** @return list<string> */
    public function permissionSlugs(): array
    {
        return $this->permissionCache ??= DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('user_roles as ur', 'ur.role_id', '=', 'rp.role_id')
            ->where('ur.user_id', $this->getKey())
            ->distinct()->pluck('p.slug')->all();
    }

    public function hasPermission(string $slug): bool
    {
        return in_array($slug, $this->permissionSlugs(), true);
    }

    /** @return list<string> */
    public function roleSlugs(): array
    {
        return $this->roles->pluck('slug')->all();
    }

    public function hasRole(RoleEnum $role): bool
    {
        return in_array($role->value, $this->roleSlugs(), true);
    }

    /** The widest scope granted by any of the user's roles: all|campaigns|team|client|own (none if no role). */
    public function scopeType(): string
    {
        if ($this->roles->isEmpty()) {
            return 'none';
        }
        $best = 'own';
        foreach ($this->roles as $role) {
            if ((self::SCOPE_RANK[$role->scope_type] ?? 0) > self::SCOPE_RANK[$best]) {
                $best = $role->scope_type;
            }
        }
        return $best;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
