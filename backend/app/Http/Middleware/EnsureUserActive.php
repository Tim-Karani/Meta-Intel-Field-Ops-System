<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (!$user->isActive() || $user->isLocked())) {
            $user->currentAccessToken()?->delete();
            return ApiResponse::error('This account is not active.', 403, [], 'account_inactive');
        }

        return $next($request);
    }
}
