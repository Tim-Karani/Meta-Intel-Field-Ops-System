<?php

namespace App\Domain\Reports;

use App\Domain\Access\AccessScope;
use App\Models\Campaign;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shelf reports: on-shelf availability (OSA) and share of shelf (SOS), each by channel, category, outlet and product,
 * plus the "Outlets not visited" coverage report.
 *
 * OSA  = checks where the product was available / all checks.
 * SOS  = our facings / all facings counted on the shelf. "All facings" is the shelf total recorded once per visit; "our
 *        facings" is the visit's own count when entered, otherwise the sum of the product facings captured in the OSA step.
 *        Category and product figures use the product facings only (a category's facings over the whole shelf total).
 * Only submitted, under-review and approved visits count, so unfinished or rejected visits never distort a report.
 */
trait ShelfReports
{
    private const SHELF_VALID = ['submitted', 'under_review', 'approved'];

    /** Column set per dimension: select expressions (aliased d_*), the matching columns, and what the first column is called. */
    private function shelfDims(): array
    {
        $category = "coalesce(pp.name, pc.name, 'Uncategorised')";

        return [
            'channel' => [
                'select' => ["coalesce(ch.name, 'Unassigned') as d_channel", 'ch.channel_group as d_group'],
                'cols' => [$this->col('channel', 'Channel', 'text'), $this->col('group', 'Channel group', 'text')],
            ],
            'category' => [
                'select' => ["$category as d_category", 'case when pp.id is not null then pc.name end as d_sub'],
                'cols' => [$this->col('category', 'Category', 'text'), $this->col('sub', 'Sub-category', 'text')],
            ],
            'outlet' => [
                'select' => ['l.id as d_id', 'l.name as d_outlet', "coalesce(ch.name, 'Unassigned') as d_channel", 'l.town as d_town'],
                'cols' => [$this->col('outlet', 'Outlet', 'text'), $this->col('channel', 'Channel', 'text'), $this->col('town', 'Town', 'text')],
            ],
            'product' => [
                'select' => ['p.id as d_id', 'p.name as d_product', 'p.sku as d_sku', "coalesce(b.name, 'No brand') as d_brand", "$category as d_category"],
                'cols' => [$this->col('product', 'Product', 'text'), $this->col('sku', 'SKU', 'text'), $this->col('brand', 'Brand', 'text'), $this->col('category', 'Category', 'text')],
            ],
        ];
    }

    /** Visits joined to their product checks, channel and category chain, with the optional report filters applied. */
    private function shelfBase(User $u, array $f, bool $approved): Builder
    {
        return $this->visitBase($u, $f, $approved)
            ->whereIn('v.status', self::SHELF_VALID)
            ->leftJoin('channels as ch', 'ch.id', '=', 'l.channel_id')
            ->join('osa_records as o', 'o.visit_id', '=', 'v.id')
            ->join('products as p', 'p.id', '=', 'o.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->leftJoin('product_categories as pp', 'pp.id', '=', 'pc.parent_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('p.id', $v))
            ->when($f['brand_id'] ?? null, fn ($q, $v) => $q->where('p.brand_id', $v))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('p.category_id', $v)->orWhere('pc.parent_id', $v)));
    }

    private function aliases(array $dim): array
    {
        return array_map(fn ($s) => trim(substr($s, strripos($s, ' as ') + 4)), $dim['select']);
    }

    private function dimRow(object $r, array $dim): array
    {
        $row = [];
        foreach ($this->aliases($dim) as $a) {
            $row[substr($a, 2)] = $r->$a;
        }
        unset($row['id']);

        return $row;
    }

    /* ----------------------------------------------------------------------------------------------- OSA */

    private function osaBy(string $by, User $u, array $f, bool $approved): array
    {
        $dim = $this->shelfDims()[$by];
        $aliases = $this->aliases($dim);
        $rows = $this->shelfBase($u, $f, $approved)->groupBy($aliases)->orderBy($aliases[$by === 'outlet' || $by === 'product' ? 1 : 0])
            ->selectRaw(implode(', ', $dim['select']).', count(distinct l.id) as outlets, count(distinct v.id) as visits, count(*) as checks, '
                .'count(*) filter (where o.available and not o.out_of_stock) as available, count(*) filter (where o.out_of_stock or not o.available) as out_of_stock')
            ->get()->map(fn ($r) => $this->dimRow($r, $dim) + [
                'outlets' => (int) $r->outlets, 'visits' => (int) $r->visits, 'checks' => (int) $r->checks,
                'available' => (int) $r->available, 'out_of_stock' => (int) $r->out_of_stock,
                'osa' => self::pct($r->available, $r->checks), 'oos' => self::pct($r->out_of_stock, $r->checks),
            ])->all();

        $checks = array_sum(array_column($rows, 'checks'));
        $avail = array_sum(array_column($rows, 'available'));
        $cols = array_merge($dim['cols'], [
            $this->col('outlets', 'Outlets'), $this->col('visits', 'Visits'), $this->col('checks', 'Checks'), $this->col('available', 'Available'),
            $this->col('out_of_stock', 'Out of stock'), $this->col('osa', 'OSA', 'percent'), $this->col('oos', 'Out-of-stock rate', 'percent'),
        ]);
        if ($by === 'outlet' || $by === 'product') {
            $cols = array_values(array_filter($cols, fn ($c) => !in_array($c['key'], ['outlets'], true) || $by === 'product'));
        }

        return [$cols, $rows, ['checks' => $checks, 'osa' => self::pct($avail, $checks), 'rows' => count($rows)]];
    }

    private function reportOsaByChannel(User $u, array $f, bool $a): array { return $this->osaBy('channel', $u, $f, $a); }
    private function reportOsaByCategory(User $u, array $f, bool $a): array { return $this->osaBy('category', $u, $f, $a); }
    private function reportOsaByOutlet(User $u, array $f, bool $a): array { return $this->osaBy('outlet', $u, $f, $a); }
    private function reportOsaByProduct(User $u, array $f, bool $a): array { return $this->osaBy('product', $u, $f, $a); }

    /* ----------------------------------------------------------------------------------------------- SOS */

    /** Shelf total per visit (and the visit's own count of our facings when entered). */
    private function visitShelfTotals(): \Illuminate\Database\Query\Builder
    {
        return DB::table('merchandising_records')->whereNotNull('total_facings')->where('total_facings', '>', 0)
            ->groupBy('visit_id')->selectRaw('visit_id, max(total_facings) as tf, max(our_facings) as mf');
    }

    private function sosBy(string $by, User $u, array $f, bool $approved): array
    {
        $dim = $this->shelfDims()[$by];
        $aliases = $this->aliases($dim);
        $fallbackTarget = (float) (\App\Domain\Projects\ProjectSettings::targetsFor($f['campaign_id'] ?? null)['sos_pct'] ?? config('field.default_sos_target_pct'));

        if ($by === 'channel' || $by === 'outlet') {
            // Whole-shelf share: one figure per visit, summed per group.
            $ours = DB::table('osa_records')->whereNotNull('facings')->groupBy('visit_id')->selectRaw('visit_id, sum(facings) as ff');
            $q = $this->visitBase($u, $f, $approved)->whereIn('v.status', self::SHELF_VALID)
                ->leftJoin('channels as ch', 'ch.id', '=', 'l.channel_id')
                ->joinSub($this->visitShelfTotals(), 't', 't.visit_id', '=', 'v.id')
                ->leftJoinSub($ours, 'fo', 'fo.visit_id', '=', 'v.id')
                ->whereRaw('coalesce(t.mf, fo.ff) is not null');
            $rows = $q->groupBy($aliases)->orderBy($aliases[$by === 'outlet' ? 1 : 0])
                ->selectRaw(implode(', ', $dim['select']).', count(distinct l.id) as outlets, count(*) as visits, sum(coalesce(t.mf, fo.ff)) as ours, sum(t.tf) as total')
                ->get();
            $target = $fallbackTarget;
        } else {
            $num = $this->shelfBase($u, $f, $approved)->joinSub($this->visitShelfTotals(), 't', 't.visit_id', '=', 'v.id')->whereNotNull('o.facings')
                ->groupBy($aliases)->selectRaw(implode(', ', $dim['select']).', count(distinct l.id) as outlets, count(distinct v.id) as visits, sum(o.facings) as ours, avg(p.sos_target_pct) as target');
            $denBase = $this->shelfBase($u, $f, $approved)->joinSub($this->visitShelfTotals(), 't', 't.visit_id', '=', 'v.id')->whereNotNull('o.facings')
                ->selectRaw('distinct '.implode(', ', $dim['select']).', v.id as vid, t.tf as tf');
            $den = DB::query()->fromSub($denBase, 'x')->groupBy($aliases)->selectRaw(implode(', ', $aliases).', sum(tf) as total')->get();
            $key = fn ($r) => implode('|', array_map(fn ($a) => (string) $r->$a, $aliases));
            $totals = [];
            foreach ($den as $d) {
                $totals[$key($d)] = (float) $d->total;
            }
            $rows = $num->get()->each(fn ($r) => $r->total = $totals[$key($r)] ?? 0)
                ->sortBy(fn ($r) => mb_strtolower((string) $r->{$aliases[$by === 'product' ? 1 : 0]}))->values();
        }

        $out = $rows->map(function ($r) use ($dim, $fallbackTarget, $by) {
            $sos = self::pct($r->ours, $r->total);
            $target = in_array($by, ['category', 'product'], true) ? (float) ($r->target ?? $fallbackTarget) : $fallbackTarget;

            return $this->dimRow($r, $dim) + [
                'outlets' => (int) $r->outlets, 'visits' => (int) $r->visits, 'our_facings' => (int) $r->ours, 'total_facings' => (int) $r->total,
                'sos' => $sos, 'target' => $target, 'gap' => $sos !== null ? round($sos - $target, 1) : null,
            ];
        })->all();

        $cols = array_merge($dim['cols'], [
            $this->col('outlets', 'Outlets'), $this->col('visits', 'Visits'), $this->col('our_facings', 'Our facings'), $this->col('total_facings', 'All facings'),
            $this->col('sos', 'Share of shelf', 'percent'), $this->col('target', 'Expected', 'percent'), $this->col('gap', 'Gap to expected', 'decimal'),
        ]);
        $ours = array_sum(array_column($out, 'our_facings'));
        $total = array_sum(array_column($out, 'total_facings'));

        return [$cols, $out, ['rows' => count($out), 'overall_sos' => in_array($by, ['channel', 'outlet'], true) ? self::pct($ours, $total) : null]];
    }

    private function reportSosByChannel(User $u, array $f, bool $a): array { return $this->sosBy('channel', $u, $f, $a); }
    private function reportSosByCategory(User $u, array $f, bool $a): array { return $this->sosBy('category', $u, $f, $a); }
    private function reportSosByOutlet(User $u, array $f, bool $a): array { return $this->sosBy('outlet', $u, $f, $a); }
    private function reportSosByProduct(User $u, array $f, bool $a): array { return $this->sosBy('product', $u, $f, $a); }

    /* ----------------------------------------------------------------------------------------------- coverage */

    /**
     * Outlets linked to the campaigns in scope that have no valid visit in the period (all time when no dates are set).
     * "Never visited" = not once, ever; "Planned, not done" = a visit was scheduled in the period but never delivered.
     */
    private function reportOutletsNotVisited(User $u, array $f, bool $approved): array
    {
        $from = $f['date_from'] ?? null;
        $to = $f['date_to'] ?? null;
        $valid = ['started', 'completed', 'submitted', 'under_review', 'approved'];
        if ($approved) {
            $valid = ['approved'];
        }

        $q = DB::table('campaign_locations as cl')
            ->join('campaigns as c', 'c.id', '=', 'cl.campaign_id')
            ->join('locations as l', 'l.id', '=', 'cl.location_id')
            ->leftJoin('channels as ch', 'ch.id', '=', 'l.channel_id')
            ->leftJoin('territories as t', 't.id', '=', 'l.territory_id')
            ->whereNull('l.deleted_at')->where('l.status', 'active')
            ->whereIn('cl.campaign_id', AccessScope::scope(Campaign::query(), $u)->where('campaigns.status', '!=', 'archived')->select('campaigns.id'))
            ->whereIn('cl.location_id', AccessScope::scope(Location::query(), $u)->select('locations.id'))
            ->when($f['campaign_id'] ?? null, fn ($q, $v) => $q->where('cl.campaign_id', $v))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('c.client_id', $v))
            ->when($f['channel_id'] ?? null, fn ($q, $v) => $q->where('l.channel_id', $v))
            ->when($f['territory_id'] ?? null, fn ($q, $v) => $q->where('l.territory_id', $v))
            ->when($f['location_id'] ?? null, fn ($q, $v) => $q->where('l.id', $v));

        $statusIn = implode(',', array_fill(0, count($valid), '?'));
        $planWindow = ($from ? ' and a.scheduled_date >= ?' : '').($to ? ' and a.scheduled_date <= ?' : '');

        $all = (clone $q)->count();
        $rows = (clone $q)->whereNotExists(function ($s) use ($valid, $from, $to) {
            $s->from('visits as v')->selectRaw('1')->whereColumn('v.location_id', 'cl.location_id')->whereColumn('v.campaign_id', 'cl.campaign_id')->whereIn('v.status', $valid)
                ->when($from, fn ($x) => $x->where('v.visit_date', '>=', $from))->when($to, fn ($x) => $x->where('v.visit_date', '<=', $to));
        })
            ->selectRaw("l.name as outlet, l.code, coalesce(ch.name, 'Unassigned') as channel, l.town, l.region, t.name as territory, c.name as campaign,
                (select max(v.visit_date) from visits v where v.location_id = cl.location_id and v.campaign_id = cl.campaign_id and v.status in ($statusIn)) as last_visit,
                exists (select 1 from assignments a where a.location_id = cl.location_id and a.campaign_id = cl.campaign_id and a.status != 'cancelled'$planWindow) as was_planned",
                array_merge($valid, $from ? [$from] : [], $to ? [$to] : []))
            ->orderBy('channel')->orderBy('l.name')->limit(5000)->get()
            ->map(fn ($r) => [
                'outlet' => $r->outlet, 'code' => $r->code, 'channel' => $r->channel, 'town' => $r->town, 'region' => $r->region, 'territory' => $r->territory, 'campaign' => $r->campaign,
                'last_visit' => $r->last_visit ? (string) $r->last_visit : null,
                'days_since' => $r->last_visit ? (int) \Carbon\Carbon::parse($r->last_visit)->startOfDay()->diffInDays(now()->startOfDay()) : null,
                'status' => $r->last_visit === null ? 'Never visited' : ($r->was_planned ? 'Planned, not done' : 'Not visited in period'),
            ])->all();

        $missed = count($rows);

        return [[
            $this->col('outlet', 'Outlet', 'text'), $this->col('code', 'Code', 'text'), $this->col('channel', 'Channel', 'text'), $this->col('town', 'Town', 'text'),
            $this->col('region', 'Region', 'text'), $this->col('territory', 'Territory', 'text'), $this->col('campaign', 'Campaign', 'text'),
            $this->col('status', 'Status', 'text'), $this->col('last_visit', 'Last visit', 'text'), $this->col('days_since', 'Days since last visit'),
        ], $rows, ['outlets_in_scope' => $all, 'not_visited' => $missed, 'visited' => max(0, $all - $missed), 'coverage' => self::pct(max(0, $all - $missed), $all)]];
    }
}
