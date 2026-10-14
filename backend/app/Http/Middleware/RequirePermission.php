<?php

namespace App\Http\Middleware;

use App\Domain\Audit\SecurityEvents;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Coarse RBAC gate (any listed permission passes). Policies add row-level checks on top. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        foreach ($permissions as $permission) {
            if ($user && $user->hasPermission($permission)) {
                return $next($request);
            }
        }

        SecurityEvents::log('permission_denied', $user?->getKey(), 'warning', [
            'required' => $permissions, 'route' => $request->route()?->uri(), 'method' => $request->method(),
        ]);

        return ApiResponse::error('You are not authorized to access this resource.', 403, [], 'forbidden');
    }
}
