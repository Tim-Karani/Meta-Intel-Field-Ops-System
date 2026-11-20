<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** Orders (quantity and value), expiries (date and quantity) and stock counts are captured on the phone and read on the web. */
class OrdersExpiriesStockCountTest extends DatabaseTestCase
{
    private Scenario $s;
    private string $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->agent);
        $this->visit = $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->api('POST', "visits/{$this->visit}/check-in", Scenario::gps())->assertOk();
    }

    public function test_an_order_is_priced_by_the_server_and_replays_do_not_duplicate(): void
    {
        $uuid = (string) Str::uuid();
        $body = ['client_uuid' => $uuid, 'items' => [['product_id' => $this->s->productA->id, 'quantity' => 4, 'unit_price' => 25.5]]];
        $a = $this->api('POST', "visits/{$this->visit}/orders", $body)->assertCreated();
        $this->assertEquals(102.0, (float) $a->json('data.total_value'));
        $b = $this->api('POST', "visits/{$this->visit}/orders", $body);
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, \App\Models\Order::query()->count());
        $this->api('POST', "visits/{$this->visit}/orders", ['items' => []])->assertStatus(422);
    }

    public function test_expiry_needs_a_date_and_quantity_and_a_campaign_product(): void
    {
        $this->api('POST', "visits/{$this->visit}/expiries", ['product_id' => $this->s->productA->id, 'quantity' => 12])->assertStatus(422);
        $this->api('POST', "visits/{$this->visit}/expiries", ['product_id' => $this->s->productA->id, 'expiry_date' => '2027-03-31', 'quantity' => 12, 'batch_no' => 'B7'])->assertCreated()
            ->assertJsonPath('data.expiry_date', '2027-03-31');
        $this->api('POST', "visits/{$this->visit}/expiries", ['product_id' => (string) Str::uuid(), 'expiry_date' => '2027-03-31', 'quantity' => 1])->assertStatus(422);
    }

    public function test_a_stock_count_is_one_row_per_product_and_totals_shelf_and_back_room(): void
    {
        $this->api('PUT', "visits/{$this->visit}/stock-counts", ['product_id' => $this->s->productA->id, 'shelf_quantity' => 6, 'backroom_quantity' => 10])->assertOk()->assertJsonPath('data.total_quantity', 16);
        $this->api('PUT', "visits/{$this->visit}/stock-counts", ['product_id' => $this->s->productA->id, 'shelf_quantity' => 0])->assertOk()->assertJsonPath('data.total_quantity', 0);
        $this->assertSame(1, \App\Models\StockCount::query()->count());
    }

    public function test_the_office_lists_reports_and_exports_them(): void
    {
        $p = $this->s->productA->id;
        $this->api('POST', "visits/{$this->visit}/orders", ['items' => [['product_id' => $p, 'quantity' => 2, 'unit_price' => 10]]])->assertCreated();
        $this->api('POST', "visits/{$this->visit}/expiries", ['product_id' => $p, 'expiry_date' => '2027-03-31', 'quantity' => 5])->assertCreated();
        $this->api('PUT', "visits/{$this->visit}/stock-counts", ['product_id' => $p, 'shelf_quantity' => 3, 'backroom_quantity' => 4])->assertOk();

        $this->s->actingAs($this->s->admin);
        foreach (['orders', 'expiries', 'stock-counts'] as $list) {
            $this->assertCount(1, $this->api('GET', $list)->assertOk()->json('data'), $list);
        }
        foreach (['orders', 'expiries', 'stock_count'] as $type) {
            $r = $this->api('GET', "reports/$type")->assertOk();
            $this->assertCount(1, $r->json('data.rows'), $type);
        }
        $this->api('GET', 'visits/'.$this->visit)->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonCount(1, 'data.stock_counts')->assertJsonCount(1, 'data.expiry_records');
    }

    public function test_listings_follow_permissions_and_scope(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('GET', 'orders')->assertForbidden();
        $this->api('GET', 'expiries')->assertForbidden();
        $this->api('GET', 'stock-counts')->assertForbidden();
        $this->s->actingAs($this->s->clientAUser);
        $this->api('GET', 'orders')->assertForbidden();
    }
}
