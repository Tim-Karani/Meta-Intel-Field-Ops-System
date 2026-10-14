<?php

namespace Tests\Security;

use App\Models\Photo;
use App\Models\Visit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** "Changing IDs in requests": every foreign object must look exactly like a missing one (404), never 403. */
class IdorTest extends DatabaseTestCase
{
    private Scenario $s;
    private string $visitId;
    private string $photoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->agent);
        $this->visitId = $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => (string) Str::uuid()])->json('data.id');
        $this->api('POST', "visits/{$this->visitId}/check-in", Scenario::gps())->assertOk();
        $this->photoId = $this->api('POST', "visits/{$this->visitId}/photos", ['file' => UploadedFile::fake()->image('p.jpg')])->assertCreated()->json('data.id');
        $this->api('POST', 'issues', ['title' => 'Mine', 'visit_id' => $this->visitId])->assertCreated();
    }

    public function test_another_agent_cannot_read_or_touch_the_visit(): void
    {
        $this->s->actingAs($this->s->otherAgent);
        $v = $this->visitId;
        $this->api('GET', "visits/$v")->assertNotFound();
        $this->api('POST', "visits/$v/check-out", Scenario::gps())->assertNotFound();
        $this->api('PUT', "visits/$v/responses", ['answers' => []])->assertNotFound();
        $this->api('POST', "visits/$v/submit")->assertNotFound();
        $this->api('GET', "visits/$v/sales")->assertNotFound();
        $this->api('POST', "visits/$v/sales", ['items' => [['product_id' => $this->s->productA->id, 'quantity' => 1]]])->assertNotFound();
        $this->api('POST', "visits/$v/photos", ['file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
        $this->assertCount(0, $this->api('GET', 'visits')->json('data'));
    }

    public function test_another_agent_cannot_start_a_visit_on_someone_elses_assignment(): void
    {
        $this->s->actingAs($this->s->otherAgent);
        $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id])->assertNotFound();
        $this->api('GET', "assignments/{$this->s->assignmentA->id}")->assertNotFound();
    }

    public function test_evidence_files_are_not_addressable_by_other_users(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->get("/api/v1/photos/{$this->photoId}/file")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // Other agents, unrelated supervisors and clients (visit not approved) all get a plain 404.
        $stranger = Scenario::user('Unrelated Supervisor', \App\Enums\Role::Supervisor);
        foreach ([$this->s->otherAgent, $stranger, $this->s->clientAUser, $this->s->clientBUser] as $user) {
            $this->s->actingAs($user);
            $this->get("/api/v1/photos/{$this->photoId}/file")->assertNotFound();
            $this->api('GET', "photos/{$this->photoId}")->assertStatus($user->hasPermission('evidence.view') ? 404 : 403);
        }

        // An in-scope reviewer can see it.
        $this->s->actingAs($this->s->supervisor);
        $this->get("/api/v1/photos/{$this->photoId}/file")->assertOk();
    }

    public function test_unsupervised_supervisor_and_unlinked_manager_see_nothing(): void
    {
        $stranger = Scenario::user('Other Supervisor', \App\Enums\Role::Supervisor);
        $this->s->actingAs($stranger);
        $this->api('GET', "visits/{$this->visitId}")->assertNotFound();
        $this->api('POST', "visits/{$this->visitId}/approve")->assertNotFound();
        $this->assertCount(0, $this->api('GET', 'visits')->json('data'));
        $this->assertCount(0, $this->api('GET', 'campaigns')->json('data'));

        $this->s->actingAs($this->s->analyst);   // campaigns scope, not linked to any campaign
        $this->assertCount(0, $this->api('GET', 'visits')->json('data'));
    }

    public function test_foreign_ids_in_request_bodies_are_rejected(): void
    {
        $this->s->actingAs($this->s->manager);   // linked to campaign A only
        $base = ['agent_id' => $this->s->agent->id, 'scheduled_date' => now()->addDay()->toDateString()];
        $this->api('POST', 'assignments', $base + ['campaign_id' => $this->s->campaignB->id, 'location_id' => $this->s->locationB->id])->assertStatus(422)->assertJsonValidationErrors(['campaign_id']);
        $this->api('POST', 'activities', ['campaign_id' => $this->s->campaignB->id, 'name' => 'Sneaky'])->assertStatus(422);
        $this->api('POST', 'kpis', ['campaign_id' => $this->s->campaignB->id, 'name' => 'k', 'metric' => 'sales_value', 'target' => 1])->assertStatus(422);
        $this->api('GET', "campaigns/{$this->s->campaignB->id}")->assertNotFound();
        $this->api('PATCH', "campaigns/{$this->s->campaignB->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->api('DELETE', "campaigns/{$this->s->campaignB->id}")->assertNotFound();
        $this->api('PUT', "campaigns/{$this->s->campaignB->id}/links/teams", ['ids' => []])->assertNotFound();
    }

    public function test_malformed_ids_are_404_not_500(): void
    {
        $this->s->actingAs($this->s->admin);
        foreach (['visits', 'campaigns', 'clients', 'assignments', 'photos', 'issues', 'users', 'forms'] as $path) {
            foreach (['1', 'abc', "1' OR '1'='1", '../../etc/passwd', '00000000-0000-0000-0000-000000000000'] as $id) {
                $this->api('GET', $path.'/'.rawurlencode($id))->assertNotFound();
            }
        }
    }

    public function test_an_issue_and_its_comments_are_scoped(): void
    {
        $issueId = \App\Models\Issue::query()->firstOrFail()->id;
        $this->s->actingAs($this->s->otherAgent);
        $this->api('GET', "issues/$issueId")->assertNotFound();
        $this->api('POST', "issues/$issueId/comments", ['body' => 'hi'])->assertNotFound();
        $this->api('POST', "issues/$issueId/status", ['status' => 'resolved', 'resolution' => 'x'])->assertForbidden();   // no issues.resolve permission
    }

    public function test_notifications_cannot_be_read_by_id_guessing(): void
    {
        $note = \App\Models\AppNotification::query()->where('user_id', $this->s->agent->id)->firstOrFail();
        $this->s->actingAs($this->s->otherAgent);
        $this->api('POST', "notifications/{$note->id}/read")->assertNotFound();
    }
}
