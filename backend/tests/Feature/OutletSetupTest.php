<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\GeoArea;
use App\Models\Location;
use App\Models\OutletStatus;
use App\Models\OutletType;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** Editable outlet lists (types, sub-types, statuses, distributors, geography) and how outlets use them. */
class OutletSetupTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->admin);
    }

    private function place(): array
    {
        $country = $this->api('POST', 'geo-areas', ['level' => 'country', 'name' => 'Country One'])->assertCreated()->json('data.id');
        $region = $this->api('POST', 'geo-areas', ['level' => 'region', 'parent_id' => $country, 'name' => 'Region One'])->assertCreated()->json('data.id');
        $locality = $this->api('POST', 'geo-areas', ['level' => 'locality', 'parent_id' => $region, 'name' => 'Locality One'])->assertCreated()->json('data.id');
        $area = $this->api('POST', 'geo-areas', ['level' => 'area', 'parent_id' => $locality, 'name' => 'Area One'])->assertCreated()->json('data.id');

        return compact('country', 'region', 'locality', 'area');
    }

    public function test_types_and_sub_types_are_editable_and_protected_while_used(): void
    {
        $id = $this->api('POST', 'outlet-types', ['name' => 'Cash and carry'])->assertCreated()->json('data.id');
        $this->assertSame('cash_and_carry', OutletType::query()->find($id)->slug);
        $this->api('POST', 'outlet-types', ['name' => 'Cash and carry'])->assertStatus(422);
        $sub = $this->api('POST', 'outlet-subtypes', ['outlet_type_id' => $id, 'name' => 'Large'])->assertCreated()->json('data.id');

        $loc = $this->api('POST', 'locations', ['name' => 'Wholesale One', 'type' => 'cash_and_carry', 'outlet_subtype_id' => $sub])->assertCreated()->json('data');
        $this->assertSame($sub, $loc['outlet_subtype_id']);
        $this->api('DELETE', "outlet-types/$id")->assertStatus(409);
        $this->api('DELETE', "outlet-subtypes/$sub")->assertStatus(409);
        // A sub-type of another type is refused.
        $other = $this->api('POST', 'outlet-subtypes', ['outlet_type_id' => OutletType::query()->where('slug', 'kiosk')->value('id'), 'name' => 'Roadside'])->assertCreated()->json('data.id');
        $this->api('PATCH', "locations/{$loc['id']}", ['outlet_subtype_id' => $other])->assertStatus(422);
        // Changing the type clears the sub-type, which belonged to the old one.
        $this->api('PATCH', "locations/{$loc['id']}", ['type' => 'kiosk'])->assertOk()->assertJsonPath('data.outlet_subtype_id', null);
        $this->api('POST', 'locations', ['name' => 'Bad type', 'type' => 'not_a_type'])->assertStatus(422);
    }

    public function test_statuses_drive_the_active_flag_and_keep_one_default(): void
    {
        $closed = OutletStatus::query()->where('name', 'Permanently closed')->first();
        $default = OutletStatus::query()->where('is_default', true)->first();
        $loc = $this->api('POST', 'locations', ['name' => 'Shop One'])->assertCreated()->json('data');
        $this->assertSame($default->id, $loc['outlet_status_id']);
        $this->assertSame('active', $loc['status']);

        $this->api('PATCH', "locations/{$loc['id']}", ['outlet_status_id' => $closed->id])->assertOk()->assertJsonPath('data.status', 'inactive');
        $new = $this->api('POST', 'outlet-statuses', ['name' => 'Refurbishing', 'tone' => 'warn', 'counts_as_active' => false])->assertCreated()->json('data.id');
        $this->api('DELETE', "outlet-statuses/{$default->id}")->assertStatus(409);                 // the default
        $this->api('DELETE', "outlet-statuses/{$closed->id}")->assertStatus(409);                  // in use
        $this->api('POST', 'outlet-statuses', ['name' => 'Bad default', 'counts_as_active' => false, 'is_default' => true])->assertStatus(409);
        $this->api('PATCH', "outlet-statuses/$new", ['is_default' => true, 'counts_as_active' => true])->assertOk();
        $this->assertSame(1, OutletStatus::query()->where('is_default', true)->count());
        $this->api('DELETE', "outlet-statuses/$new")->assertStatus(409);
    }

    public function test_geography_levels_are_enforced_and_fill_the_outlet_text(): void
    {
        $p = $this->place();
        $this->api('POST', 'geo-areas', ['level' => 'area', 'parent_id' => $p['region'], 'name' => 'Skips a level'])->assertStatus(422);
        $this->api('POST', 'geo-areas', ['level' => 'region', 'parent_id' => $p['country'], 'name' => 'region one'])->assertStatus(422);   // duplicate
        $loc = $this->api('POST', 'locations', ['name' => 'Shop Two', 'geo_area_id' => $p['area']])->assertCreated()->json('data');
        $this->assertSame('Region One', $loc['region']);
        $this->assertSame('Locality One', $loc['locality']);
        $this->assertSame('Area One', $loc['town']);
        $this->api('DELETE', "geo-areas/{$p['region']}")->assertStatus(409);
        $this->api('DELETE', "geo-areas/{$p['area']}")->assertStatus(409);     // an outlet is located there
        // Filtering by a region finds outlets in everything below it.
        $this->assertCount(1, $this->api('GET', 'locations', ['geo_area_id' => $p['region']])->json('data'));
    }

    public function test_batch_update_changes_only_what_is_sent(): void
    {
        $a = Scenario::location('Batch A', -1.2, 36.8);
        $b = Scenario::location('Batch B', -1.21, 36.81);
        $d = Distributor::query()->create(['name' => 'Distributor One']);
        $this->api('POST', 'locations/batch', ['ids' => [$a->id, $b->id], 'changes' => ['distributor_id' => $d->id]])->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertSame($d->id, Location::query()->find($a->id)->distributor_id);
        $this->assertSame('Batch A', Location::query()->find($a->id)->name);
        $this->api('POST', 'locations/batch', ['ids' => [$a->id], 'changes' => []])->assertStatus(422);
        $this->api('POST', 'locations/batch', ['ids' => [$a->id], 'changes' => ['name' => 'Hacked']])->assertStatus(422);   // unknown fields are not accepted
        $this->s->actingAs($this->s->agent);
        $this->api('POST', 'locations/batch', ['ids' => [$a->id], 'changes' => ['distributor_id' => null]])->assertStatus(403);
    }

    public function test_import_creates_subtype_distributor_status_and_places_by_name(): void
    {
        $rows = [['name' => 'Imported One', 'code' => 'IMP1', 'type' => 'Bar / restaurant', 'subtype' => 'Lounge', 'distributor' => 'Distributor Two', 'status' => 'Temporarily closed',
            'region' => 'Region Two', 'locality' => 'Locality Two', 'area' => 'Area Two', 'phone' => '0700000000', 'shop_no' => '7', 'setting' => 'urban']];
        $dry = $this->api('POST', 'locations/import', ['rows' => $rows, 'dry_run' => true])->assertOk();
        $this->assertSame(1, $dry->json('data.created'));
        $this->assertFalse(Distributor::query()->where('name', 'Distributor Two')->exists());   // a preview creates nothing
        $this->api('POST', 'locations/import', ['rows' => $rows])->assertOk()->assertJsonPath('data.created', 1);
        $loc = Location::query()->where('code', 'IMP1')->first();
        $this->assertSame('bar_restaurant', $loc->type);
        $this->assertSame('inactive', $loc->status);
        $this->assertSame('Region Two', $loc->region);
        $this->assertSame('Area Two', $loc->town);
        $this->assertNotNull($loc->distributor_id);
        $this->assertNotNull($loc->outlet_subtype_id);
        $this->assertSame(1, GeoArea::query()->where('level', 'region')->where('name', 'Region Two')->count());
        // A locality without a region is refused with a clear message.
        $bad = $this->api('POST', 'locations/import', ['rows' => [['name' => 'No region', 'locality' => 'Somewhere']]])->assertOk();
        $this->assertSame(1, $bad->json('data.rejected'));
    }

    public function test_summary_groups_outlets(): void
    {
        $this->api('POST', 'locations', ['name' => 'Summary Shop'])->assertCreated();
        $rows = $this->api('GET', 'locations/summary', ['group_by' => 'status'])->assertOk()->json('data');
        $this->assertContains('Active', array_column($rows, 'name'));
        $this->api('GET', 'locations/summary', ['group_by' => 'nonsense'])->assertStatus(422);
        $this->assertNotEmpty($this->api('GET', 'locations/summary')->json('data'));      // by chain; unassigned outlets show too
    }
}
