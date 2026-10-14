<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'username' => $this->username, 'phone' => $this->phone,
            'status' => $this->status->value, 'client_id' => $this->client_id, 'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name])->all()),
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'agent_profile' => $this->whenLoaded('agentProfile', fn () => $this->agentProfile?->only(['agent_type', 'agent_code', 'supervisor_id', 'region'])),
        ];
    }

    /** The authenticated user's session payload, including effective permissions. */
    public static function session(\App\Models\User $user): array
    {
        $user->loadMissing(['roles', 'client:id,name', 'agentProfile']);

        return array_merge((new self($user))->resolve(), ['permissions' => $user->permissionSlugs(), 'scope_type' => $user->scopeType()]);
    }
}
