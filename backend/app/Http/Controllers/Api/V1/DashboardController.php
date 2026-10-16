<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Reports\DashboardService;
use App\Models\Campaign;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DashboardController extends ApiController
{
    public function __construct(private DashboardService $dashboard) {}

    /**
     * Field agents get their personal dashboard; everyone else gets the scoped operational dashboard,
     * optionally focused on one client and/or one campaign and a period (?client_id=&campaign_id=&date_from=&date_to=).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->scopeType() === 'own') {
            return $this->ok(['kind' => 'agent'] + $this->dashboard->agentSummary($user));
        }

        $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']]);
        $filters = array_filter($request->only(['date_from', 'date_to']), fn ($v) => is_string($v) && $v !== '');
        if (($clientId = $this->filterValue($request, 'client_id', true)) !== null) {
            AccessScope::scope(Client::query(), $user)->findOrFail($clientId);   // hidden = 404, like everywhere else
            $filters['client_id'] = $clientId;
        }
        if (($campaignId = $this->filterValue($request, 'campaign_id', true)) !== null) {
            $campaign = AccessScope::scope(Campaign::query(), $user)->findOrFail($campaignId);
            if (isset($filters['client_id']) && $campaign->client_id !== $filters['client_id']) {
                throw ValidationException::withMessages(['campaign_id' => ['The campaign does not belong to the selected client.']]);
            }
            $filters['campaign_id'] = $campaignId;
        }

        return $this->ok(['kind' => 'operations'] + $this->dashboard->summary($user, $filters));
    }
}
