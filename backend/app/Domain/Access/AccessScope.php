<?php

namespace App\Domain\Access;

use App\Enums\VisitStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Row-level visibility: THE single place that answers "which rows may this user see?".
 *
 *   all       whole agency (system admin, operations manager)
 *   campaigns campaigns attached via campaign_users (campaign manager, analyst)
 *   team      those campaigns AND only the agents the user supervises (supervisor)
 *   own       only the user's own records (field agent)
 *   client    only the user's client, only APPROVED data (client user)
 *
 * Policies use allows(); list endpoints use scope(). Both go through this class, so a list can
 * never leak a row the detail endpoint would refuse.
 */
class AccessScope
{
    public static function scope(Builder $query, User $user): Builder
    {
        $method = 'for'.class_basename($query->getModel());
        if (!method_exists(self::class, $method)) {
            throw new LogicException('No access scope defined for '.get_class($query->getModel()));
        }

        return self::$method($query, $user);
    }

    public static function allows(User $user, Model $model): bool
    {
        return self::scope($model->newQuery(), $user)->whereKey($model->getKey())->exists();
    }

    /* ------------------------------------------------------------------ helpers */

    /** Campaign ids visible to the user, or null when unrestricted. */
    public static function campaignIds(User $user): ?QueryBuilder
    {
        $id = $user->getKey();

        return match ($user->scopeType()) {
            'all' => null,
            'campaigns' => DB::table('campaign_users')->where('user_id', $id)->select('campaign_id'),
            'team' => DB::table('campaign_users')->where('user_id', $id)->select('campaign_id')
                ->union(DB::table('campaign_teams as ct')->join('teams as t', 't.id', '=', 'ct.team_id')->where('t.supervisor_id', $id)->select('ct.campaign_id'))
                ->union(DB::table('assignments')->where('supervisor_id', $id)->select('campaign_id')),
            'own' => DB::table('assignments')->where('agent_id', $id)->select('campaign_id'),
            'client' => DB::table('campaigns')->where('client_id', $user->client_id)->whereNull('deleted_at')->select('id as campaign_id'),
            default => DB::table('campaigns')->whereRaw('1 = 0')->select('id as campaign_id'),
        };
    }

    /** Agents (user ids) supervised by this user through agent_profiles or supervised teams. */
    public static function supervisedAgentIds(User $user): QueryBuilder
    {
        return DB::table('agent_profiles')->where('supervisor_id', $user->getKey())->select('user_id')
            ->union(DB::table('team_members as tm')->join('teams as t', 't.id', '=', 'tm.team_id')->where('t.supervisor_id', $user->getKey())->select('tm.user_id'));
    }

    private static function none(Builder $q): Builder
    {
        return $q->whereRaw('1 = 0');
    }

    private static function byCampaign(Builder $q, User $user, string $column = 'campaign_id'): Builder
    {
        $ids = self::campaignIds($user);
        return $ids === null ? $q : $q->whereIn($q->qualifyColumn($column), $ids);
    }

    private static function byVisit(Builder $q, User $user): Builder
    {
        return $q->whereIn($q->qualifyColumn('visit_id'), self::forVisit(\App\Models\Visit::query(), $user)->select('visits.id'));
    }

    private static function isInternal(User $user): bool
    {
        return in_array($user->scopeType(), ['all', 'campaigns', 'team'], true);
    }

    /* ------------------------------------------------------------------ scopes (one per model) */

    public static function forClient(Builder $q, User $user): Builder
    {
        return match ($user->scopeType()) {
            'all' => $q,
            'client' => $q->whereKey($user->client_id),
            'campaigns', 'team' => $q->whereIn('clients.id', DB::table('campaigns')->whereNull('deleted_at')->whereIn('id', self::campaignIds($user))->select('client_id')),
            default => self::none($q),
        };
    }

    public static function forCampaign(Builder $q, User $user): Builder
    {
        $ids = self::campaignIds($user);
        return $ids === null ? $q : $q->whereIn('campaigns.id', $ids);
    }

    public static function forActivity(Builder $q, User $user): Builder
    {
        return self::byCampaign($q, $user);
    }

    public static function forCampaignKpi(Builder $q, User $user): Builder
    {
        return self::byCampaign($q, $user);
    }

    public static function forTeam(Builder $q, User $user): Builder
    {
        $viaCampaign = fn () => DB::table('campaign_teams')->whereIn('campaign_id', self::campaignIds($user))->select('team_id');

        return match ($user->scopeType()) {
            'all' => $q,
            'team' => $q->where(fn ($w) => $w->where('teams.supervisor_id', $user->getKey())->orWhereIn('teams.id', $viaCampaign())),
            'campaigns' => $q->whereIn('teams.id', $viaCampaign()),
            'own' => $q->whereIn('teams.id', DB::table('team_members')->where('user_id', $user->getKey())->select('team_id')),
            default => self::none($q),
        };
    }

    public static function forTerritory(Builder $q, User $user): Builder
    {
        return self::isInternal($user) ? $q : self::none($q);
    }

    public static function forRoute(Builder $q, User $user): Builder
    {
        if (self::isInternal($user)) {
            return $q;
        }
        if ($user->scopeType() === 'own') {
            $locations = DB::table('assignments')->where('agent_id', $user->getKey())->select('location_id');
            return $q->whereIn('routes.id', DB::table('route_locations')->whereIn('location_id', $locations)->select('route_id'));
        }
        return self::none($q);
    }

    public static function forLocation(Builder $q, User $user): Builder
    {
        $campaignLocations = fn () => DB::table('campaign_locations')->whereIn('campaign_id', self::campaignIds($user))->select('location_id');

        return match ($user->scopeType()) {
            'all' => $q,
            'campaigns', 'team' => $q->where(fn ($w) => $w->whereIn('locations.id', $campaignLocations())->orWhere('locations.created_by', $user->getKey())),
            'own' => $q->whereIn('locations.id', DB::table('assignments')->where('agent_id', $user->getKey())->select('location_id')),
            'client' => $q->where(fn ($w) => $w->where('locations.client_id', $user->client_id)->orWhereIn('locations.id', $campaignLocations())),
            default => self::none($q),
        };
    }

    public static function forBrand(Builder $q, User $user): Builder
    {
        return match ($user->scopeType()) {
            'all', 'campaigns', 'team' => $q,
            'client' => $q->where('brands.client_id', $user->client_id),
            default => self::none($q),
        };
    }

    public static function forProductCategory(Builder $q, User $user): Builder
    {
        return self::isInternal($user) || $user->scopeType() === 'own' ? $q : self::none($q);
    }

    public static function forProduct(Builder $q, User $user): Builder
    {
        $linked = fn () => DB::table('campaign_products')->whereIn('campaign_id', self::campaignIds($user))->select('product_id');

        return match ($user->scopeType()) {
            'all' => $q,
            'campaigns', 'team' => $q->where(fn ($w) => $w->whereIn('products.id', $linked())->orWhereNull('products.client_id')),
            'own' => $q->whereIn('products.id', $linked()),
            'client' => $q->where(fn ($w) => $w->where('products.client_id', $user->client_id)->orWhereIn('products.id', $linked())),
            default => self::none($q),
        };
    }

    public static function forForm(Builder $q, User $user): Builder
    {
        if (self::isInternal($user)) {
            return $q;
        }
        if ($user->scopeType() === 'own') {
            return $q->where(fn ($w) => $w
                ->whereIn('forms.id', DB::table('assignments')->where('agent_id', $user->getKey())->whereNotNull('form_id')->select('form_id'))
                ->orWhereIn('forms.id', DB::table('campaign_forms')->whereIn('campaign_id', self::campaignIds($user))->select('form_id')));
        }
        return self::none($q);
    }

    public static function forAssignment(Builder $q, User $user): Builder
    {
        return match ($user->scopeType()) {
            'all' => $q,
            'campaigns' => self::byCampaign($q, $user),
            'team' => self::byCampaign($q, $user)->where(fn ($w) => $w
                ->whereIn('assignments.agent_id', self::supervisedAgentIds($user))->orWhere('assignments.supervisor_id', $user->getKey())),
            'own' => $q->where('assignments.agent_id', $user->getKey()),
            default => self::none($q),
        };
    }

    public static function forVisit(Builder $q, User $user): Builder
    {
        return match ($user->scopeType()) {
            'all' => $q,
            'campaigns' => self::byCampaign($q, $user),
            'team' => self::byCampaign($q, $user)->where(fn ($w) => $w
                ->whereIn('visits.agent_id', self::supervisedAgentIds($user))
                ->orWhereIn('visits.assignment_id', DB::table('assignments')->where('supervisor_id', $user->getKey())->select('id'))),
            'own' => $q->where('visits.agent_id', $user->getKey()),
            // Client users only ever see APPROVED visits of their own client.
            'client' => self::byCampaign($q, $user)->where('visits.status', VisitStatus::Approved->value),
            default => self::none($q),
        };
    }

    public static function forSale(Builder $q, User $user): Builder
    {
        return self::byVisit($q, $user);
    }

    public static function forOsaRecord(Builder $q, User $user): Builder
    {
        return self::byVisit($q, $user);
    }

    public static function forMerchandisingRecord(Builder $q, User $user): Builder
    {
        return self::byVisit($q, $user);
    }

    public static function forPhoto(Builder $q, User $user): Builder
    {
        $q = self::byVisit($q, $user);
        return $user->scopeType() === 'client' ? $q->where('photos.review_status', 'accepted') : $q;
    }

    public static function forAttendance(Builder $q, User $user): Builder
    {
        return match ($user->scopeType()) {
            'all' => $q,
            'campaigns' => $q->whereIn('attendance.user_id', DB::table('assignments')->whereIn('campaign_id', self::campaignIds($user))->select('agent_id')),
            'team' => $q->whereIn('attendance.user_id', self::supervisedAgentIds($user)),
            'own' => $q->where('attendance.user_id', $user->getKey()),
            default => self::none($q),
        };
    }

    public static function forIssue(Builder $q, User $user): Builder
    {
        $me = $user->getKey();

        return match ($user->scopeType()) {
            'all' => $q,
            'campaigns' => $q->where(fn ($w) => $w->whereIn('issues.campaign_id', self::campaignIds($user))->orWhere('issues.reported_by', $me)->orWhere('issues.assigned_to', $me)),
            'team' => $q->where(fn ($w) => $w
                ->where(fn ($x) => $x->whereIn('issues.campaign_id', self::campaignIds($user))
                    ->where(fn ($y) => $y->whereIn('issues.reported_by', self::supervisedAgentIds($user))->orWhereNull('issues.reported_by')))
                ->orWhere('issues.reported_by', $me)->orWhere('issues.assigned_to', $me)),
            'own' => $q->where(fn ($w) => $w->where('issues.reported_by', $me)->orWhere('issues.assigned_to', $me)),
            default => self::none($q),
        };
    }

    public static function forAppNotification(Builder $q, User $user): Builder
    {
        return $q->where('notifications.user_id', $user->getKey());
    }

    public static function forUser(Builder $q, User $user): Builder
    {
        return match ($user->scopeType()) {
            'all' => $q,
            'team' => $q->where(fn ($w) => $w->whereKey($user->getKey())->orWhereIn('users.id', self::supervisedAgentIds($user))),
            default => $q->whereKey($user->getKey()),
        };
    }

    /** Active field agents the user may assign work to. */
    public static function assignableAgents(Builder $q, User $user): Builder
    {
        $q->whereHas('roles', fn ($r) => $r->where('slug', 'field_agent'))->where('users.status', 'active');

        return match ($user->scopeType()) {
            'all', 'campaigns' => $q,
            'team' => $q->whereIn('users.id', self::supervisedAgentIds($user)),
            default => self::none($q),
        };
    }

    public static function forAuditLog(Builder $q, User $user): Builder
    {
        return $user->scopeType() === 'all' ? $q : self::none($q);
    }
}
