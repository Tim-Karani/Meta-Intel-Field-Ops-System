<?php

namespace Tests\Feature;

use App\Models\Location;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** An outlet shows who last changed it; the audit log keeps the full before/after history for the administrator. */
class OutletLastEditorTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_the_last_person_to_edit_an_outlet_is_recorded_and_the_creator_is_kept(): void
    {
        $this->s->actingAs($this->s->ops);
        $loc = $this->api('POST', 'locations', ['name' => 'Corner Shop', 'type' => 'kiosk', 'phone' => '0700000001'])->assertCreated()->json('data');
        $this->assertSame($this->s->ops->id, $loc['created_by']);
        $this->assertSame($this->s->ops->id, Location::query()->findOrFail($loc['id'])->updated_by);

        $this->s->actingAs($this->s->admin);
        $this->api('PATCH', "locations/{$loc['id']}", ['phone' => '0700000002', 'name' => 'Corner Shop Ltd'])->assertOk()
            ->assertJsonPath('data.updater.name', $this->s->admin->name)
            ->assertJsonPath('data.creator.name', $this->s->ops->name);

        $fresh = Location::query()->findOrFail($loc['id']);
        $this->assertSame($this->s->admin->id, $fresh->updated_by);
        $this->assertSame($this->s->ops->id, $fresh->created_by);
    }

    public function test_every_change_is_in_the_audit_log_with_who_and_before_after(): void
    {
        $this->s->actingAs($this->s->ops);
        $loc = $this->api('POST', 'locations', ['name' => 'Audit Shop', 'type' => 'kiosk'])->assertCreated()->json('data');

        $this->s->actingAs($this->s->admin);
        $this->api('PATCH', "locations/{$loc['id']}", ['name' => 'Audit Shop 2'])->assertOk();

        $rows = $this->api('GET', 'audit-logs', ['subject_id' => $loc['id']])->assertOk()->json('data');
        $update = collect($rows)->firstWhere('action', 'updated');
        $this->assertNotNull($update);
        $this->assertSame($this->s->admin->id, $update['actor']['id']);
        $this->assertSame('Audit Shop', $update['before']['name']);
        $this->assertSame('Audit Shop 2', $update['after']['name']);
    }

    public function test_a_save_with_no_real_change_does_not_change_the_last_editor(): void
    {
        $this->s->actingAs($this->s->ops);
        $loc = $this->api('POST', 'locations', ['name' => 'Same Shop', 'type' => 'kiosk'])->assertCreated()->json('data');

        $this->s->actingAs($this->s->admin);
        $this->api('PATCH', "locations/{$loc['id']}", ['name' => 'Same Shop'])->assertOk();

        $this->assertSame($this->s->ops->id, Location::query()->findOrFail($loc['id'])->updated_by);
    }
}
