<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Audit\AuditService;
use App\Domain\Projects\ProjectSettings;
use App\Models\Campaign;
use App\Models\StockMovement;
use App\Models\User;
use App\Rules\Accessible;
use App\Support\DomainRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Stock accountability for projects where agents sell (sales reps, foot soldiers): what each agent was given, what they handed back,
 * what they sold, and what they should still be holding. Only projects with the Sales task switched on use it.
 */
class StockController extends ApiController
{
    /** @return \Illuminate\Database\Query\Builder|null agents the caller may see, null = everyone */
    private function agentScope(User $user)
    {
        return $user->scopeType() === 'team' ? AccessScope::supervisedAgentIds($user) : null;
    }

    private function campaign(Request $request, string $id): Campaign
    {
        $this->assertUuid($id);

        return AccessScope::scope(Campaign::query(), $request->user())->findOrFail($id);
    }

    /** GET /stock?campaign_id= — one row per agent and product. */
    public function index(Request $request): JsonResponse
    {
        $campaign = $this->campaign($request, (string) $request->query('campaign_id'));
        $agentScope = $this->agentScope($request->user());
        $agentId = $request->query('agent_id');

        $moves = StockMovement::query()->where('campaign_id', $campaign->id)
            ->when($agentScope, fn ($q) => $q->whereIn('agent_id', $agentScope))->when($agentId, fn ($q, $v) => $q->where('agent_id', $v))
            ->selectRaw("agent_id, product_id,
                coalesce(sum(case when kind = 'issue' then quantity else 0 end), 0) as issued,
                coalesce(sum(case when kind = 'return' then quantity else 0 end), 0) as returned,
                coalesce(sum(case when kind = 'adjust' then quantity else 0 end), 0) as adjusted")
            ->groupBy('agent_id', 'product_id')->get();

        $sold = DB::table('sales_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->join('visits as v', 'v.id', '=', 's.visit_id')
            ->where('v.campaign_id', $campaign->id)->where('v.status', '!=', 'rejected')
            ->when($agentScope, fn ($q) => $q->whereIn('v.agent_id', $agentScope))->when($agentId, fn ($q, $v) => $q->where('v.agent_id', $v))
            ->groupBy('v.agent_id', 'si.product_id')->selectRaw('v.agent_id, si.product_id, sum(si.quantity) as qty, sum(si.line_total) as value')->get()
            ->keyBy(fn ($r) => $r->agent_id.'|'.$r->product_id);

        $keys = $moves->map(fn ($m) => [$m->agent_id, $m->product_id])->merge($sold->map(fn ($r) => [$r->agent_id, $r->product_id]))->unique(fn ($k) => $k[0].'|'.$k[1])->values();
        $moveBy = $moves->keyBy(fn ($m) => $m->agent_id.'|'.$m->product_id);
        $names = User::query()->whereIn('id', $keys->pluck(0))->pluck('name', 'id');
        $products = \App\Models\Product::query()->whereIn('id', $keys->pluck(1))->pluck('name', 'id');

        $rows = $keys->map(function ($k) use ($moveBy, $sold, $names, $products) {
            $m = $moveBy[$k[0].'|'.$k[1]] ?? null;
            $s = $sold[$k[0].'|'.$k[1]] ?? null;
            $issued = (int) ($m->issued ?? 0) + (int) ($m->adjusted ?? 0);
            $returned = (int) ($m->returned ?? 0);
            $qty = (int) ($s->qty ?? 0);

            return ['agent_id' => $k[0], 'agent' => $names[$k[0]] ?? '—', 'product_id' => $k[1], 'product' => $products[$k[1]] ?? '—',
                'issued' => $issued, 'returned' => $returned, 'sold' => $qty, 'sales_value' => round((float) ($s->value ?? 0), 2), 'balance' => $issued - $returned - $qty];
        })->sortBy(fn ($r) => $r['agent'].'|'.$r['product'])->values();

        return $this->ok(['campaign' => ['id' => $campaign->id, 'name' => $campaign->name, 'sales_enabled' => ProjectSettings::salesEnabled($campaign)], 'rows' => $rows]);
    }

    /** GET /stock/movements?campaign_id= — the hand-over log. */
    public function movements(Request $request): JsonResponse
    {
        $campaign = $this->campaign($request, (string) $request->query('campaign_id'));
        $agentScope = $this->agentScope($request->user());
        $rows = StockMovement::query()->where('campaign_id', $campaign->id)->with(['agent:id,name', 'product:id,name'])
            ->when($agentScope, fn ($q) => $q->whereIn('agent_id', $agentScope))->when($request->query('agent_id'), fn ($q, $v) => $q->where('agent_id', $v))
            ->latest()->limit(200)->get()->map(fn ($m) => ['id' => $m->id, 'agent' => $m->agent?->name, 'product' => $m->product?->name, 'kind' => $m->kind,
                'quantity' => $m->quantity, 'note' => $m->note, 'created_at' => $m->created_at?->toIso8601String()]);

        return $this->ok($rows);
    }

    /** POST /stock — hand stock to an agent, take stock back, or correct a count. */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'campaign_id' => ['required', 'uuid', new Accessible(Campaign::class)],
            'agent_id' => ['required', 'uuid', 'exists:users,id'],
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'kind' => ['required', Rule::in(['issue', 'return', 'adjust'])],
            'quantity' => ['required', 'integer', 'between:-100000,100000', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        if ($d['kind'] !== 'adjust' && $d['quantity'] < 0) {
            throw new DomainRuleException('Enter a positive quantity. Use "adjust" to correct a count downwards.', 'invalid_quantity', 422);
        }
        if ($d['kind'] === 'adjust' && trim((string) ($d['note'] ?? '')) === '') {
            throw new DomainRuleException('Say why the count is being adjusted.', 'reason_required', 422);
        }
        $campaign = $this->campaign($request, $d['campaign_id']);
        if (!ProjectSettings::salesEnabled($campaign)) {
            throw new DomainRuleException('This project does not record sales, so it does not issue stock. Turn the Sales task on in the project setup first.', 'sales_not_enabled', 422);
        }
        $scope = $this->agentScope($request->user());
        if ($scope && !(clone $scope)->where('user_id', $d['agent_id'])->exists()) {
            throw new DomainRuleException('That person is not on your team.', 'forbidden', 403);
        }
        if (!User::query()->whereKey($d['agent_id'])->whereHas('roles', fn ($r) => $r->whereIn('slug', ['field_agent', 'team_leader']))->exists()) {
            throw new DomainRuleException('Stock can only be issued to people who work in the field.', 'invalid_agent', 422);
        }

        $m = StockMovement::query()->create($d + ['created_by' => $request->user()->getKey()]);
        AuditService::record('stock.'.$d['kind'], 'stock', $m, null, ['agent_id' => $d['agent_id'], 'product_id' => $d['product_id'], 'quantity' => $d['quantity']], ['campaign_id' => $campaign->id]);

        return $this->created(['id' => $m->id], 'Stock movement saved.');
    }
}
