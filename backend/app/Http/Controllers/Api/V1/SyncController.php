<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Forms\FormStructureService;
use App\Models\AppNotification;
use App\Models\Assignment;
use App\Models\Form;
use App\Models\Product;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read side of mobile synchronisation (the write side is the existing idempotent endpoints: every create
 * accepts a client_uuid, so a retried upload never duplicates).
 *
 *   GET /sync/bootstrap          everything the agent needs to work offline: a window of assignments, the forms and
 *                                products they use, the agent's own visits, recent notifications.
 *   GET /sync/changes?since=ISO  the same shapes, restricted to rows changed after `since`. `assignment_ids` is always
 *                                the full current id list so the app can spot assignments that were removed or
 *                                reassigned (the server is authoritative for assignments; the app keeps any local,
 *                                unsynced work and tells the user).
 *
 * Everything goes through AccessScope, so an agent only ever receives their own rows.
 */
class SyncController extends ApiController
{
    public function __construct(private FormStructureService $forms) {}

    public function bootstrap(Request $request): JsonResponse
    {
        return $this->ok($this->payload($request, null));
    }

    public function changes(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['required', 'date']]);

        return $this->ok($this->payload($request, Carbon::parse($data['since'])->subSeconds(2)));   // small overlap: clock skew is harmless, rows are upserted by id
    }

    private function payload(Request $request, ?Carbon $since): array
    {
        $user = $request->user();
        $serverTime = now();
        $from = $serverTime->copy()->subDays(3)->toDateString();
        $to = $serverTime->copy()->addDays(14)->toDateString();

        $assignmentScope = fn () => AccessScope::scope(Assignment::query(), $user)->whereBetween('assignments.scheduled_date', [$from, $to]);

        $assignmentIds = $assignmentScope()->pluck('assignments.id')->all();

        $aq = $assignmentScope()->with([
            'campaign:id,name,code,client_id', 'campaign.client:id,name', 'activity:id,name',
            'location:id,name,town,address,latitude,longitude,geofence_radius_m,needs_review,created_via', 'supervisor:id,name',
        ]);
        $visitScope = AccessScope::scope(Visit::query(), $user)->whereBetween('visits.visit_date', [$from, $to]);
        $nq = AppNotification::query()->where('user_id', $user->getKey());
        if ($since) {
            $aq->where('assignments.updated_at', '>', $since);
            $visitScope->where('visits.updated_at', '>', $since);
            $nq->where('updated_at', '>', $since);
        }

        $assignments = $aq->orderBy('scheduled_date')->orderBy('start_time')->limit(500)->get();
        $visits = $visitScope->with(['checkin', 'checkout', 'responses'])->orderBy('visit_date')->limit(500)->get();
        $notifications = $nq->orderByDesc('created_at')->limit(50)->get();

        // Forms and products: everything the visible assignments/visits use (changed ones only for a delta).
        $formIds = $assignments->pluck('form_id')->merge($visits->pluck('form_id'))->filter()->unique();
        if ($since) {
            $allFormIds = AccessScope::scope(Assignment::query(), $user)->whereBetween('assignments.scheduled_date', [$from, $to])->pluck('form_id')->filter()->unique();
            $formIds = $allFormIds;
        }
        $formQ = Form::query()->whereIn('id', $formIds);
        $formsChanged = $since ? $formQ->where('updated_at', '>', $since)->get() : $formQ->get();

        $campaignIds = AccessScope::scope(Assignment::query(), $user)->whereBetween('assignments.scheduled_date', [$from, $to])->pluck('campaign_id')->unique();
        $productQ = Product::query()->where('status', 'active')
            ->whereIn('id', DB::table('campaign_products')->whereIn('campaign_id', $campaignIds)->select('product_id'));
        // Products are always sent whole (a small list); a link added to an unchanged product must not be missed.

        return [
            'server_time' => $serverTime->toIso8601String(),
            'full' => $since === null,
            'window' => ['from' => $from, 'to' => $to],
            'assignment_ids' => $assignmentIds,
            'assignments' => $assignments->map(fn ($a) => $a->toArray() + ['allowed_transitions' => array_map(fn ($s) => $s->value, $a->status->next())])->map(function (array $a) {
                $a['status'] = is_object($a['status'] ?? null) ? $a['status']->value : $a['status'];

                return $a;
            })->all(),
            'visits' => $visits->map(fn ($v) => $v->toArray() + ['answers' => $v->responses->pluck('value', 'question_id')])->all(),
            'forms' => $formsChanged->map(fn ($f) => $f->toArray() + ['sections' => $this->forms->export($f)])->all(),
            'products' => $this->products($productQ->get(['id', 'client_id', 'brand_id', 'category_id', 'name', 'sku', 'unit_price', 'status']), $campaignIds),
            'notifications' => $notifications->all(),
        ];
    }

    /** Each product carries the campaign ids (among the agent's visible ones) it belongs to, so the app can offer only the right products per visit. */
    private function products($products, $campaignIds): array
    {
        $links = DB::table('campaign_products')->whereIn('product_id', $products->pluck('id'))->whereIn('campaign_id', $campaignIds)->get()->groupBy('product_id');

        return $products->map(fn ($p) => $p->toArray() + ['campaign_ids' => $links->get($p->id, collect())->pluck('campaign_id')->values()->all()])->all();
    }
}
