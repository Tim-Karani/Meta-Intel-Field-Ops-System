<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

class ResourceCrudTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_manager_builds_the_chain_client_campaign_activity_location_assignment(): void
    {
        $this->s->actingAs($this->s->ops);

        $client = $this->api('POST', 'clients', ['name' => 'Acme Foods', 'code' => 'ACME'])->assertCreated()->json('data');
        $campaign = $this->api('POST', 'campaigns', ['client_id' => $client['id'], 'code' => 'ACME-1', 'name' => 'Launch', 'start_date' => '2026-11-01', 'end_date' => '2026-12-01', 'status' => 'active'])
            ->assertCreated()->json('data');
        $this->assertSame('draft', $campaign['status'], 'status cannot be set through create');

        $activity = $this->api('POST', 'activities', ['campaign_id' => $campaign['id'], 'name' => 'Sampling', 'activity_type' => 'sampling'])->assertCreated()->json('data');
        $location = $this->api('POST', 'locations', ['name' => 'Acme Mart', 'latitude' => -1.28, 'longitude' => 36.82, 'geofence_radius_m' => 120])->assertCreated()->json('data');
        $this->api('PUT', "campaigns/{$campaign['id']}/links/locations", ['ids' => [$location['id']]])->assertOk();

        // draft -> planned -> active, and assignments become possible.
        $this->api('POST', "campaigns/{$campaign['id']}/transition", ['status' => 'active'])->assertStatus(409);
        $this->api('POST', "campaigns/{$campaign['id']}/transition", ['status' => 'planned'])->assertOk();
        $agent = $this->s->agent;
        $this->api('POST', 'assignments', [
            'campaign_id' => $campaign['id'], 'activity_id' => $activity['id'], 'agent_id' => $agent->id, 'location_id' => $location['id'], 'scheduled_date' => now()->addDay()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->api('POST', "campaigns/{$campaign['id']}/transition", ['status' => 'active'])->assertOk();
    }

    public function test_validation_failures_use_the_standard_envelope(): void
    {
        $this->s->actingAs($this->s->ops);
        $this->api('POST', 'clients', ['name' => ''])->assertStatus(422)->assertJsonStructure(['success', 'message', 'errors' => ['name']]);
        $this->api('POST', 'campaigns', ['client_id' => 'not-a-uuid', 'code' => 'x y', 'name' => 'n'])->assertStatus(422)->assertJsonValidationErrors(['client_id', 'code']);
        $this->api('POST', 'campaigns', ['client_id' => $this->s->clientA->id, 'code' => 'D1', 'name' => 'n', 'start_date' => '2026-12-01', 'end_date' => '2026-11-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    }

    public function test_unique_codes_and_client_deletion_rules(): void
    {
        $this->s->actingAs($this->s->ops);
        $this->api('POST', 'clients', ['name' => 'Dup', 'code' => 'CA'])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->api('DELETE', "clients/{$this->s->clientA->id}")->assertStatus(409)->assertJsonPath('code', 'client_has_campaigns');
        $empty = Client::query()->create(['name' => 'Empty']);
        $this->api('DELETE', "clients/{$empty->id}")->assertOk();
        $this->assertSoftDeleted('clients', ['id' => $empty->id]);
    }

    public function test_search_filter_sort_and_pagination(): void
    {
        $this->s->actingAs($this->s->ops);
        foreach (range(1, 25) as $i) {
            Client::query()->create(['name' => sprintf('Bulk Client %02d', $i)]);
        }
        $page = $this->api('GET', 'clients', ['search' => 'bulk client', 'per_page' => 10, 'sort' => '-name'])->assertOk();
        $this->assertCount(10, $page->json('data'));
        $this->assertSame(25, $page->json('meta.total'));
        $this->assertSame('Bulk Client 25', $page->json('data.0.name'));

        // Wildcards are escaped, unknown sort columns fall back, per_page is capped.
        $this->api('GET', 'clients', ['search' => '%'])->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'clients', ['sort' => 'password; drop table clients'])->assertOk();
        $this->assertLessThanOrEqual(100, $this->api('GET', 'clients', ['per_page' => 100000])->json('meta.per_page'));
    }

    public function test_changes_are_audited_with_before_and_after_and_audit_is_append_only(): void
    {
        $this->s->actingAs($this->s->ops);
        $this->api('PATCH', "clients/{$this->s->clientA->id}", ['name' => 'Client A (renamed)'])->assertOk();

        $log = AuditLog::query()->where('module', 'clients')->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($this->s->ops->id, $log->actor_id);
        $this->assertSame('Client A', $log->before['name']);
        $this->assertSame('Client A (renamed)', $log->after['name']);
        $this->assertNotNull($log->request_id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
    }

    public function test_campaign_links_validate_ids_and_protect_locations_in_use(): void
    {
        $this->s->actingAs($this->s->ops);
        $id = $this->s->campaignA->id;
        $this->api('PUT', "campaigns/$id/links/locations", ['ids' => [(string) \Illuminate\Support\Str::uuid()]])->assertStatus(422);
        // locationA has an open assignment, so it cannot be unlinked.
        $this->api('PUT', "campaigns/$id/links/locations", ['ids' => []])->assertStatus(409)->assertJsonPath('code', 'locations_in_use');
        // Another client's product cannot be linked.
        $foreign = \App\Models\Product::query()->create(['client_id' => $this->s->clientB->id, 'name' => 'Foreign']);
        $this->api('PUT', "campaigns/$id/links/products", ['ids' => [$foreign->id]])->assertStatus(422);
        $this->api('PUT', "campaigns/$id/links/bogus", ['ids' => []])->assertNotFound();
    }

    public function test_assignment_rules(): void
    {
        $this->s->actingAs($this->s->ops);
        $base = ['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'location_id' => $this->s->locationA->id];
        $day = now()->addDays(2)->toDateString();

        $this->api('POST', 'assignments', $base + ['scheduled_date' => $day, 'start_time' => '09:00', 'end_time' => '11:00'])->assertCreated();
        $this->api('POST', 'assignments', $base + ['scheduled_date' => $day, 'start_time' => '10:00', 'end_time' => '12:00'])->assertStatus(422);   // overlap
        $this->api('POST', 'assignments', $base + ['scheduled_date' => now()->subDay()->toDateString()])->assertStatus(422);                           // past
        $this->api('POST', 'assignments', ['location_id' => $this->s->locationB->id] + $base + ['scheduled_date' => $day])->assertStatus(422);        // not in campaign

        // Bulk is all-or-nothing.
        $before = \App\Models\Assignment::query()->count();
        $this->api('POST', 'assignments/bulk', ['assignments' => [
            $base + ['scheduled_date' => now()->addDays(5)->toDateString()],
            $base + ['scheduled_date' => 'garbage'],
        ]])->assertStatus(422);
        $this->assertSame($before, \App\Models\Assignment::query()->count());
    }

    public function test_agent_accepts_only_their_own_assignment(): void
    {
        $this->s->actingAs($this->s->otherAgent);
        $this->api('POST', "assignments/{$this->s->assignmentA->id}/accept")->assertNotFound();
        $this->s->actingAs($this->s->agent);
        $this->api('POST', "assignments/{$this->s->assignmentA->id}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
    }

    public function test_issue_lifecycle(): void
    {
        $this->s->actingAs($this->s->agent);
        $issue = $this->api('POST', 'issues', ['title' => 'Shelf damaged', 'campaign_id' => $this->s->campaignA->id, 'priority' => 'high'])->assertCreated()->json('data');

        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', "issues/{$issue['id']}/assign", ['assigned_to' => $this->s->supervisor->id])->assertOk()->assertJsonPath('data.status', 'assigned');
        $this->api('POST', "issues/{$issue['id']}/status", ['status' => 'closed'])->assertStatus(409);
        $this->api('POST', "issues/{$issue['id']}/status", ['status' => 'resolved'])->assertStatus(422);   // resolution required
        $this->api('POST', "issues/{$issue['id']}/status", ['status' => 'resolved', 'resolution' => 'Replaced shelf'])->assertOk();
        $this->api('POST', "issues/{$issue['id']}/comments", ['body' => 'Done'])->assertCreated();
        $this->api('POST', "issues/{$issue['id']}/status", ['status' => 'closed'])->assertOk();
        $this->api('PATCH', "issues/{$issue['id']}", ['title' => 'x'])->assertStatus(409);

        $this->assertGreaterThanOrEqual(4, \App\Models\IssueComment::query()->where('issue_id', $issue['id'])->count());
    }

    public function test_attendance_clock_in_is_idempotent_per_day_and_flags_late_arrivals(): void
    {
        $this->s->assignmentA->update(['start_time' => now()->subHour()->format('H:i:s')]);
        $this->s->actingAs($this->s->agent);
        $first = $this->api('POST', 'attendance/clock-in', ['latitude' => -1.26, 'longitude' => 36.81])->assertCreated()->assertJsonPath('data.status', 'late')->json('data.id');
        $this->api('POST', 'attendance/clock-in', [])->assertOk()->assertJsonPath('data.id', $first);
        $this->api('POST', 'attendance/clock-out', [])->assertOk();
        $this->api('POST', 'attendance/clock-out', [])->assertOk()->assertJsonPath('message', 'Already clocked out.');
    }

    public function test_notifications_are_private_and_markable(): void
    {
        $this->s->actingAs($this->s->agent);
        $list = $this->api('GET', 'notifications')->assertOk();
        $this->assertGreaterThanOrEqual(1, $list->json('meta.unread'));   // "new assignment"
        $id = $list->json('data.0.id');
        $this->api('POST', "notifications/$id/read")->assertOk();

        $this->s->actingAs($this->s->otherAgent);
        $this->api('POST', "notifications/$id/read")->assertNotFound();
        $this->api('POST', 'notifications/read-all')->assertOk();
    }
}
