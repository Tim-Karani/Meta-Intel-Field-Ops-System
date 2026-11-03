<?php

namespace Tests\Feature;

use App\Models\Location;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** v1.3.2: bulk outlet import (CSV rows -> locations, optionally linked to a campaign). */
class OutletImportTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->s->actingAs($this->s->admin);
        $before = Location::count();
        $this->api('POST', 'locations/import', ['dry_run' => true, 'rows' => [['name' => 'Outlet One', 'town' => 'Nairobi']]])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.dry_run', true);
        $this->assertSame($before, Location::count());
    }

    public function test_import_creates_links_and_reports_bad_rows(): void
    {
        $this->s->actingAs($this->s->admin);
        $res = $this->api('POST', 'locations/import', [
            'campaign_id' => $this->s->campaignA->id,
            'rows' => [
                ['name' => 'Bulk One', 'town' => 'Nairobi', 'latitude' => '-1.28', 'longitude' => '36.82'],
                ['name' => 'Bulk Two', 'town' => 'Mombasa'],
                ['name' => 'Bad Coords', 'latitude' => '95', 'longitude' => '36.8'],
                ['name' => 'Bulk One', 'town' => 'Nairobi'],
            ],
        ])->assertOk();
        $res->assertJsonPath('data.created', 2)->assertJsonPath('data.rejected', 2);
        $one = Location::where('name', 'Bulk One')->firstOrFail();
        $this->assertNotNull($one->latitude);
        $this->assertTrue($this->s->campaignA->locations()->where('locations.id', $one->id)->exists());
        $this->assertFalse(Location::where('name', 'Bad Coords')->exists());
    }

    public function test_existing_outlet_is_reused_not_duplicated(): void
    {
        $this->s->actingAs($this->s->admin);
        $existing = $this->s->locationA;
        $before = Location::count();
        $this->api('POST', 'locations/import', ['campaign_id' => $this->s->campaignA->id, 'rows' => [['name' => $existing->name, 'code' => $existing->code, 'town' => $existing->town]]])
            ->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.linked_existing', 1);
        $this->assertSame($before, Location::count());
    }

    public function test_agent_cannot_import(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('POST', 'locations/import', ['rows' => [['name' => 'Nope']]])->assertForbidden();
    }
}
