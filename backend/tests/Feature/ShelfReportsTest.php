<?php

namespace Tests\Feature;

use App\Domain\Assignments\AssignmentService;
use App\Models\Brand;
use App\Models\Channel;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** OSA and share-of-shelf reports by channel / category / outlet / product, plus outlets not visited. */
class ShelfReportsTest extends DatabaseTestCase
{
    private Scenario $s;
    private Channel $chainX;
    private Channel $chainY;
    private Location $outletX;     // chain X, visited
    private Location $outletY;     // chain Y, visited
    private Location $outletNone;  // no chain, never visited
    private Product $cola;         // category Drinks > Cola (sub)
    private Product $juice;        // category Drinks (main only)

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->s = Scenario::build();

        $this->chainX = Channel::query()->create(['name' => 'Chain X', 'channel_group' => 'Group One']);
        $this->chainY = Channel::query()->create(['name' => 'Chain Y', 'channel_group' => 'Group One']);
        $this->outletX = Scenario::location('Outlet X', -1.20, 36.80);
        $this->outletY = Scenario::location('Outlet Y', -1.21, 36.81);
        $this->outletNone = Scenario::location('Outlet None', -1.22, 36.82);
        $this->outletX->update(['channel_id' => $this->chainX->id, 'town' => 'Town One']);
        $this->outletY->update(['channel_id' => $this->chainY->id]);
        $s->campaignA->locations()->syncWithoutDetaching([$this->outletX->id, $this->outletY->id, $this->outletNone->id]);

        $drinks = ProductCategory::query()->create(['name' => 'Drinks']);
        $sub = ProductCategory::query()->create(['name' => 'Cola', 'parent_id' => $drinks->id]);
        $brand = Brand::query()->create(['name' => 'Brand One']);
        $this->cola = Product::query()->create(['name' => 'Cola 1L', 'sku' => 'C1', 'category_id' => $sub->id, 'brand_id' => $brand->id, 'sos_target_pct' => 30]);
        $this->juice = Product::query()->create(['name' => 'Juice 1L', 'sku' => 'J1', 'category_id' => $drinks->id]);

        // Outlet X: cola available (6 facings), juice out of stock; shelf total 20.
        $this->visit($this->outletX, [[$this->cola, true, 6], [$this->juice, false, 0]], 20);
        // Outlet Y: both available (cola 2, juice 3); shelf total 10.
        $this->visit($this->outletY, [[$this->cola, true, 2], [$this->juice, true, 3]], 10);
    }

    private function visit(Location $loc, array $checks, int $totalFacings, string $status = 'approved'): Visit
    {
        $a = app(AssignmentService::class)->create(['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'location_id' => $loc->id, 'scheduled_date' => now()->toDateString()], $this->s->manager);
        $v = Visit::query()->create(['assignment_id' => $a->id, 'campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'location_id' => $loc->id, 'status' => $status, 'visit_date' => now()->toDateString()]);
        foreach ($checks as [$product, $available, $facings]) {
            DB::table('osa_records')->insert(['id' => (string) Str::uuid(), 'visit_id' => $v->id, 'product_id' => $product->id, 'available' => $available, 'out_of_stock' => !$available, 'facings' => $facings, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('merchandising_records')->insert(['id' => (string) Str::uuid(), 'visit_id' => $v->id, 'total_facings' => $totalFacings, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $v;
    }

    private function rows(string $type, array $q = []): array
    {
        $this->s->actingAs($this->s->admin);

        return $this->api('GET', "reports/$type", $q)->assertOk()->json('data');
    }

    public function test_osa_by_channel_groups_outlets_under_their_chain(): void
    {
        $r = $this->rows('osa_by_channel');
        $by = collect($r['rows'])->keyBy('channel');
        $this->assertSame(50.0, (float) $by['Chain X']['osa']);          // cola ok, juice out
        $this->assertSame(100.0, (float) $by['Chain Y']['osa']);
        $this->assertSame('Group One', $by['Chain X']['group']);
        $this->assertSame(2, $by['Chain X']['checks']);
        $this->assertSame(75.0, (float) $r['summary']['osa']);
    }

    public function test_osa_by_category_rolls_sub_categories_into_the_main_category(): void
    {
        $rows = collect($this->rows('osa_by_category')['rows']);
        $this->assertSame(['Drinks'], $rows->pluck('category')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['Cola', null], $rows->pluck('sub')->all());
        $this->assertSame(100.0, (float) $rows->firstWhere('sub', 'Cola')['osa']);
        $this->assertSame(50.0, (float) $rows->firstWhere('sub', null)['osa']);
    }

    public function test_osa_by_outlet_and_product(): void
    {
        $outlets = collect($this->rows('osa_by_outlet')['rows'])->keyBy('outlet');
        $this->assertSame(50.0, (float) $outlets['Outlet X']['osa']);
        $this->assertSame('Chain X', $outlets['Outlet X']['channel']);
        $products = collect($this->rows('osa_by_product')['rows'])->keyBy('product');
        $this->assertSame(100.0, (float) $products['Cola 1L']['osa']);
        $this->assertSame(50.0, (float) $products['Juice 1L']['osa']);
        $this->assertSame('Brand One', $products['Cola 1L']['brand']);
    }

    public function test_filters_narrow_the_shelf_reports(): void
    {
        $rows = $this->rows('osa_by_outlet', ['channel_id' => $this->chainX->id])['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('Outlet X', $rows[0]['outlet']);
        $rows = $this->rows('osa_by_product', ['product_id' => $this->juice->id])['rows'];
        $this->assertCount(1, $rows);
    }

    public function test_unfinished_and_rejected_visits_do_not_count(): void
    {
        $this->visit($this->outletNone, [[$this->cola, false, 0]], 10, 'rejected');
        $this->visit($this->outletNone, [[$this->cola, false, 0]], 10, 'started');
        $by = collect($this->rows('osa_by_channel')['rows'])->keyBy('channel');
        $this->assertArrayNotHasKey('Unassigned', $by->all());
    }

    public function test_sos_by_outlet_uses_product_facings_over_the_shelf_total(): void
    {
        $by = collect($this->rows('sos_by_outlet')['rows'])->keyBy('outlet');
        $this->assertSame(30.0, (float) $by['Outlet X']['sos']);       // 6 / 20
        $this->assertSame(50.0, (float) $by['Outlet Y']['sos']);       // 5 / 10
    }

    public function test_sos_by_channel_category_and_product(): void
    {
        $ch = collect($this->rows('sos_by_channel')['rows'])->keyBy('channel');
        $this->assertSame(30.0, (float) $ch['Chain X']['sos']);
        $this->assertSame(50.0, (float) $ch['Chain Y']['sos']);

        $cat = collect($this->rows('sos_by_category')['rows']);
        $drinks = $cat->firstWhere('sub', null);                       // juice: (0 + 3) / (20 + 10)
        $this->assertSame(10.0, (float) $drinks['sos']);
        $cola = $cat->firstWhere('sub', 'Cola');                        // (6 + 2) / (20 + 10)
        $this->assertEqualsWithDelta(26.7, (float) $cola['sos'], 0.1);

        $prod = collect($this->rows('sos_by_product')['rows'])->keyBy('product');
        $this->assertEqualsWithDelta(26.7, (float) $prod['Cola 1L']['sos'], 0.1);
        $this->assertSame(30.0, (float) $prod['Cola 1L']['target']);
        $this->assertEqualsWithDelta(-3.3, (float) $prod['Cola 1L']['gap'], 0.1);
    }

    public function test_outlets_not_visited_lists_only_unvisited_outlets(): void
    {
        $r = $this->rows('outlets_not_visited');
        $names = collect($r['rows'])->pluck('outlet')->all();
        $this->assertContains('Outlet None', $names);
        $this->assertNotContains('Outlet X', $names);
        $this->assertNotContains('Outlet Y', $names);
        $none = collect($r['rows'])->firstWhere('outlet', 'Outlet None');
        $this->assertSame('Unassigned', $none['channel']);
        $this->assertSame('Never visited', $none['status']);
        $this->assertGreaterThan(0, $r['summary']['not_visited']);
        $this->assertLessThan(100, $r['summary']['coverage']);
    }

    public function test_outlets_not_visited_respects_the_period(): void
    {
        $r = $this->rows('outlets_not_visited', ['date_from' => now()->addDays(5)->toDateString(), 'date_to' => now()->addDays(9)->toDateString()]);
        $names = collect($r['rows'])->pluck('outlet')->all();
        $this->assertContains('Outlet X', $names);                      // visited, but not in that window
        $this->assertSame('Not visited in period', collect($r['rows'])->firstWhere('outlet', 'Outlet X')['status']);
    }

    public function test_reports_stay_inside_the_users_scope(): void
    {
        $this->s->actingAs($this->s->clientBUser);
        // Client users only get the portal: the internal shelf reports are closed to them.
        $this->api('GET', 'reports/osa_by_outlet')->assertForbidden();
        $this->api('GET', 'reports/outlets_not_visited')->assertForbidden();
    }

    public function test_chains_crud_and_import_by_chain_name(): void
    {
        $this->s->actingAs($this->s->admin);
        $id = $this->api('POST', 'channels', ['name' => 'Chain Z', 'channel_group' => 'Group Two'])->assertCreated()->json('data.id');
        $this->api('POST', 'channels', ['name' => 'Chain Z'])->assertStatus(422);
        $res = $this->api('POST', 'locations/import', ['rows' => [
            ['name' => 'Branch One', 'code' => 'B1', 'channel' => 'chain z', 'region' => 'Region One', 'distributor' => 'Dist One'],
            ['name' => 'Branch Two', 'code' => 'B2', 'channel' => 'Brand New Chain', 'channel_group' => 'Group Three'],
        ]])->assertOk();
        $this->assertSame(2, $res->json('data.created'));
        $this->assertSame($id, Location::query()->where('code', 'B1')->value('channel_id'));
        $this->assertTrue(Channel::query()->where('name', 'Brand New Chain')->where('channel_group', 'Group Three')->exists());
        $this->assertSame(1, Channel::query()->where('name', 'Chain Z')->count());     // matched case-insensitively, not duplicated
        $this->api('DELETE', "channels/$id")->assertStatus(409);                       // outlets still belong to it
        $this->api('GET', 'locations', ['channel_id' => $id])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sub_category_rules(): void
    {
        $this->s->actingAs($this->s->admin);
        $main = ProductCategory::query()->where('name', 'Drinks')->first();
        $sub = ProductCategory::query()->where('name', 'Cola')->first();
        $this->api('POST', 'product-categories', ['name' => 'Too deep', 'parent_id' => $sub->id])->assertStatus(422);
        $this->api('DELETE', "product-categories/{$main->id}")->assertStatus(409);
    }
}
