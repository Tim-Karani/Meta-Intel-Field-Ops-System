<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends ApiController
{
    /** Roles with their permission slugs, plus the permission catalogue (for the RBAC matrix screen). */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()->with('permissions:id,slug')->orderBy('id')->get()->map(fn ($r) => [
            'slug' => $r->slug, 'name' => $r->name, 'scope_type' => $r->scope_type,
            'permissions' => $r->permissions->pluck('slug')->sort()->values()->all(),
        ]);

        return $this->ok(['roles' => $roles, 'permissions' => Permission::query()->orderBy('module')->orderBy('slug')->get(['slug', 'module'])]);
    }
}
