<?php

namespace App\Domain\Access;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The client / project the person has chosen in the scope bar. It only narrows what the user could already see
 * (every query still starts from AccessScope), so it can never widen access.
 */
final class ProjectFocus
{
    /** Subquery of campaign ids matching ?client_id= / ?campaign_id=, or null when neither is given (or malformed). */
    public static function campaignIds(array $input): ?Builder
    {
        $client = self::uuid($input['client_id'] ?? null);
        $campaign = self::uuid($input['campaign_id'] ?? null);
        if (!$client && !$campaign) {
            return null;
        }

        return DB::table('campaigns')->select('campaigns.id')
            ->when($client, fn ($q) => $q->where('campaigns.client_id', $client))
            ->when($campaign, fn ($q) => $q->where('campaigns.id', $campaign));
    }

    /**
     * Narrows a planning list (clients, projects, forms, teams, outlets, products, territories, routes) to the focused
     * client / project through the links the system already keeps. Tables without such a link are left untouched.
     */
    public static function restrict(\Illuminate\Database\Eloquent\Builder $query, string $table, array $input): \Illuminate\Database\Eloquent\Builder
    {
        $focus = self::campaignIds($input);
        if (!$focus) {
            return $query;
        }
        $col = fn (string $c) => $table.'.'.$c;
        $viaLocations = fn () => DB::table('campaign_locations')->whereIn('campaign_id', $focus)->select('location_id');

        return match ($table) {
            'campaigns' => $query->whereIn($col('id'), $focus),
            'clients' => $query->whereIn($col('id'), DB::table('campaigns')->whereIn('id', $focus)->select('client_id')),
            'forms' => $query->whereIn($col('id'), DB::table('campaign_forms')->whereIn('campaign_id', $focus)->select('form_id')),
            'teams' => $query->whereIn($col('id'), DB::table('campaign_teams')->whereIn('campaign_id', $focus)->select('team_id')),
            'locations' => $query->whereIn($col('id'), $viaLocations()),
            'territories' => $query->whereIn($col('id'), DB::table('locations')->whereIn('id', $viaLocations())->whereNotNull('territory_id')->select('territory_id')),
            'routes' => $query->whereIn($col('id'), DB::table('route_locations')->whereIn('location_id', $viaLocations())->select('route_id')),
            // Products: a chosen project narrows to its product list; a chosen client alone is handled by the client_id filter.
            'products' => self::uuid($input['campaign_id'] ?? null) ? $query->whereIn($col('id'), DB::table('campaign_products')->whereIn('campaign_id', $focus)->select('product_id')) : $query,
            default => $query,
        };
    }

    /** Agents who are assigned to, or attached to, the focused campaigns. */
    public static function agentIds(Builder $campaignIds): Builder
    {
        return DB::table('assignments')->whereIn('campaign_id', $campaignIds)->select('agent_id as id')
            ->union(DB::table('campaign_users')->whereIn('campaign_id', $campaignIds)->select('user_id as id'));
    }

    private static function uuid(mixed $v): ?string
    {
        return is_string($v) && Str::isUuid($v) ? $v : null;
    }
}
