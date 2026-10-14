<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __construct(private DashboardService $dashboard) {}

    /** Field agents get their personal dashboard; everyone else gets the scoped operational dashboard. */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok($user->scopeType() === 'own'
            ? ['kind' => 'agent'] + $this->dashboard->agentSummary($user)
            : ['kind' => 'operations'] + $this->dashboard->summary($user));
    }
}
