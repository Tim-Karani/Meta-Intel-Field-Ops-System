<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Enums\AssignmentStatus;
use App\Enums\CampaignStatus;
use App\Enums\GpsOutcome;
use App\Enums\IssueStatus;
use App\Enums\QuestionType;
use App\Enums\VisitStatus;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Form;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Team;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Small id/name lists for pickers (always scoped), plus the enumerations the UI needs. */
class LookupController extends ApiController
{
    private const ENTITIES = [
        'clients' => [Client::class, 'name'], 'campaigns' => [Campaign::class, 'name'], 'activities' => [Activity::class, 'name'],
        'locations' => [Location::class, 'name'], 'teams' => [Team::class, 'name'], 'territories' => [Territory::class, 'name'],
        'products' => [Product::class, 'name'], 'forms' => [Form::class, 'name'],
    ];

    public function enums(): JsonResponse
    {
        $values = fn (string $enum) => array_map(fn ($c) => $c->value, $enum::cases());

        return $this->ok([
            'campaign_status' => $values(CampaignStatus::class), 'assignment_status' => $values(AssignmentStatus::class),
            'visit_status' => $values(VisitStatus::class), 'gps_outcome' => $values(GpsOutcome::class), 'issue_status' => $values(IssueStatus::class),
            'question_types' => $values(QuestionType::class),
            'issue_priority' => ['low', 'medium', 'high', 'critical'],
            'location_types' => ['retail_store', 'supermarket', 'wholesale', 'kiosk', 'pharmacy', 'bar_restaurant', 'market', 'event_venue', 'other'],
            'agent_types' => ['merchandiser', 'brand_ambassador', 'promoter', 'sales_rep', 'enumerator', 'other'],
            'payment_methods' => ['cash', 'mpesa', 'card', 'credit', 'other'],
            'kpi_metrics' => CampaignKpiController::METRICS,
            'visit_frequencies' => ['once', 'daily', 'weekly', 'biweekly', 'monthly'],
            'roles' => Role::query()->orderBy('id')->get(['slug', 'name', 'scope_type']),
            'currency' => 'KES',
        ]);
    }

    public function options(Request $request, string $entity): JsonResponse
    {
        if ($entity === 'agents' || $entity === 'supervisors' || $entity === 'staff') {
            abort_unless($request->user()->scopeType() !== 'own' && $request->user()->scopeType() !== 'client', 403);
            $q = AccessScope::scope(User::query(), $request->user())->whereNull('users.client_id')->where('users.status', 'active');
            if ($entity !== 'staff') {
                $slug = $entity === 'agents' ? 'field_agent' : 'supervisor';
                $q->whereHas('roles', fn ($r) => $r->where('slug', $slug));
            }
            $name = 'users.name';
            $select = ['users.id', 'users.name'];
        } else {
            abort_unless(isset(self::ENTITIES[$entity]), 404);
            [$model, $col] = self::ENTITIES[$entity];
            abort_if($request->user()->scopeType() === 'client', 403);
            $q = AccessScope::scope($model::query(), $request->user());
            $name = (new $model)->qualifyColumn($col);
            $select = [(new $model)->qualifyColumn('id'), $name.' as name'];
            foreach (['client_id', 'campaign_id', 'status'] as $f) {
                if (($v = $this->filterValue($request, $f, str_ends_with($f, '_id'))) !== null && \Illuminate\Support\Facades\Schema::hasColumn((new $model)->getTable(), $f)) {
                    $q->where((new $model)->qualifyColumn($f), $v);
                }
            }
        }
        if (($term = $this->searchTerm($request)) !== '') {
            $q->where($name, 'ilike', '%'.addcslashes($term, '%_\\').'%');
        }

        return $this->ok($q->orderBy($name)->limit(200)->get($select));
    }
}
