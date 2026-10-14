<?php

namespace Tests\Security;

use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** An authorised client user sees only their own client's APPROVED information, and nothing internal. */
class ClientIsolationTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_client_users_are_locked_out_of_every_internal_endpoint(): void
    {
        $this->s->actingAs($this->s->clientAUser);
        foreach (['users', 'roles', 'clients', 'campaigns', 'activities', 'teams', 'locations', 'products', 'forms', 'assignments', 'attendance', 'visits', 'sales', 'osa', 'merchandising', 'photos', 'issues',
            'reports', 'audit-logs', 'security-events', 'system/health', 'system/settings', 'kpis', 'territories', 'routes', 'brands', 'lookups/clients', 'dashboard'] as $path) {
            $status = $this->api('GET', $path)->status();
            $this->assertContains($status, [200, 403], "$path returned $status");
            if ($path !== 'dashboard') {
                $this->assertSame(403, $status, "client user must not read /$path");
            }
        }
        $this->api('POST', 'clients', ['name' => 'x'])->assertForbidden();
        $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id])->assertForbidden();
    }

    public function test_portal_is_limited_to_the_users_own_client(): void
    {
        $this->s->actingAs($this->s->clientAUser);
        $names = collect($this->api('GET', 'portal/campaigns')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['CAMP-A'], $names);

        $this->s->actingAs($this->s->clientBUser);
        $this->assertSame(['CAMP-B'], collect($this->api('GET', 'portal/campaigns')->json('data'))->pluck('code')->all());
        $this->api('GET', 'portal/visits', ['campaign_id' => $this->s->campaignA->id])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_portal_csv_export_is_client_scoped_audited_and_hides_agent_columns(): void
    {
        $this->s->actingAs($this->s->clientAUser);
        $res = $this->api('GET', 'portal/reports/campaign_performance/export')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $this->assertStringContainsString('Campaign', explode("\n", $csv)[0]);
        $this->assertStringNotContainsString('Agent', explode("\n", $csv)[0]);
        $this->assertDatabaseHas('report_exports', ['user_id' => $this->s->clientAUser->id, 'report_type' => 'campaign_performance']);

        $this->api('GET', 'portal/reports/attendance/export')->assertNotFound();   // workforce reports are not offered to clients
        $this->s->actingAs($this->s->supervisor);
        $this->api('GET', 'portal/reports/campaign_performance/export')->assertForbidden();
    }

    public function test_unapproved_visits_and_their_evidence_stay_hidden(): void
    {
        $v = \App\Models\Visit::query()->create([
            'assignment_id' => $this->s->assignmentA->id, 'campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id,
            'location_id' => $this->s->locationA->id, 'status' => 'submitted', 'visit_date' => now()->toDateString(),
        ]);
        $this->s->actingAs($this->s->clientAUser);
        $this->api('GET', 'portal/visits')->assertJsonCount(0, 'data');
        $this->api('GET', "portal/visits/{$v->id}")->assertNotFound();

        $v->update(['status' => 'approved']);
        $this->api('GET', 'portal/visits')->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString($this->s->agent->email, $this->api('GET', "portal/visits/{$v->id}")->getContent());
    }

    public function test_pending_and_rejected_photos_are_not_served_to_clients(): void
    {
        $v = \App\Models\Visit::query()->create([
            'assignment_id' => $this->s->assignmentA->id, 'campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id,
            'location_id' => $this->s->locationA->id, 'status' => 'approved', 'visit_date' => now()->toDateString(),
        ]);
        \Illuminate\Support\Facades\Storage::disk('evidence')->put('visits/x.jpg', 'jpg');
        $photo = fn (string $status) => \App\Models\Photo::query()->create([
            'visit_id' => $v->id, 'taken_by' => $this->s->agent->id, 'kind' => 'evidence', 'disk' => 'evidence', 'path' => 'visits/x.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 3, 'sha256' => str_repeat('a', 64), 'review_status' => $status,
        ]);
        $pending = $photo('pending');
        $rejected = $photo('rejected');
        $accepted = $photo('accepted');

        $this->s->actingAs($this->s->clientAUser);
        $this->get("/api/v1/photos/{$pending->id}/file")->assertNotFound();
        $this->get("/api/v1/photos/{$rejected->id}/file")->assertNotFound();
        $this->get("/api/v1/photos/{$accepted->id}/file")->assertOk();
        $this->assertCount(1, $this->api('GET', "portal/visits/{$v->id}")->json('data.photos'));
        $this->assertSame($accepted->id, $this->api('GET', "portal/visits/{$v->id}")->json('data.photos.0.id'));

        $this->s->actingAs($this->s->clientBUser);
        $this->api('GET', "portal/visits/{$v->id}")->assertNotFound();
    }

    public function test_client_cannot_be_linked_to_a_staff_role_or_another_client_by_a_non_admin(): void
    {
        $this->s->actingAs($this->s->ops);   // can view users but not create/edit them
        $this->api('POST', 'users', ['name' => 'x', 'email' => 'x@example.test', 'role' => 'client_user', 'client_id' => $this->s->clientA->id])->assertForbidden();
        $this->s->actingAs($this->s->admin);
        $this->api('POST', 'users', ['name' => 'x', 'email' => 'x@example.test', 'role' => 'client_user'])->assertStatus(422);                                   // needs a client
        $this->api('POST', 'users', ['name' => 'y', 'email' => 'y@example.test', 'role' => 'field_agent', 'client_id' => $this->s->clientA->id])->assertStatus(422); // staff cannot have a client
    }
}
