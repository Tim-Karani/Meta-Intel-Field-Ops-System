<?php

namespace Tests\Feature;

use App\Models\Visit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** The critical scenario from docs/TESTING.md, end to end through the HTTP API. */
class VisitWorkflowTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    private function startVisit(?string $clientUuid = null): string
    {
        $this->s->actingAs($this->s->agent);

        return $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => $clientUuid ?? (string) Str::uuid(), 'device_id' => 'test-device'])
            ->assertCreated()->json('data.id');
    }

    private function completeVisit(string $id, bool $withPhoto = true): void
    {
        $q = $this->s->questionIds();
        $this->api('POST', "visits/$id/check-in", Scenario::gps())->assertOk()->assertJsonPath('data.status', 'started')->assertJsonPath('data.gps_outcome', 'valid');
        $this->api('PUT', "visits/$id/responses", ['answers' => [$q['Stocked?'] => true, $q['Facings'] => 6]])->assertOk();
        $this->api('POST', "visits/$id/sales", ['items' => [['product_id' => $this->s->productA->id, 'quantity' => 3]], 'payment_method' => 'mpesa'])
            ->assertCreated()->assertJsonPath('data.total_value', '180.00');
        $this->api('PUT', "visits/$id/osa", ['product_id' => $this->s->productA->id, 'available' => true, 'facings' => 4])->assertOk();
        $this->api('POST', "visits/$id/merchandising", ['posm_present' => true, 'posm_type' => 'shelf strip'])->assertCreated();
        if ($withPhoto) {
            $this->api('POST', "visits/$id/photos", ['file' => UploadedFile::fake()->image('shelf.jpg', 800, 600), 'question_id' => $q['Shelf photo']])->assertCreated();
        }
        $this->api('POST', "visits/$id/check-out", Scenario::gps())->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_full_lifecycle_from_assignment_to_client_visibility(): void
    {
        $id = $this->startVisit();
        $this->completeVisit($id);
        $this->api('POST', "visits/$id/submit")->assertOk()->assertJsonPath('data.status', 'submitted');

        // The assignment completes and the supervisor is told.
        $this->assertSame('completed', $this->s->assignmentA->refresh()->status->value);

        // Client user: nothing is visible before approval.
        $this->s->actingAs($this->s->clientAUser);
        $this->api('GET', 'portal/visits')->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "portal/visits/$id")->assertNotFound();

        // Supervisor reviews and approves.
        $this->s->actingAs($this->s->supervisor);
        $this->api('GET', 'visits', ['needs_review' => 1])->assertOk()->assertJsonCount(1, 'data');
        $this->api('POST', "visits/$id/review")->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->api('POST', "visits/$id/approve", ['note' => 'Good work'])->assertOk()->assertJsonPath('data.status', 'approved');

        // Dashboard and reports include it.
        $this->api('GET', 'reports/visit_completion', ['campaign_id' => $this->s->campaignA->id])->assertOk();
        $sales = $this->api('GET', 'reports/sales', ['campaign_id' => $this->s->campaignA->id])->assertOk()->json('data');
        $this->assertSame(180.0, (float) collect($sales['rows'])->sum('value'));

        // Client user now sees the approved visit, with no agent identity.
        $this->s->actingAs($this->s->clientAUser);
        $res = $this->api('GET', "portal/visits/$id")->assertOk();
        $this->assertStringNotContainsString($this->s->agent->name, $res->getContent());
        $this->assertStringNotContainsString($this->s->agent->id, $res->getContent());
        $this->assertCount(1, $this->api('GET', 'portal/visits')->json('data'));

        // Client B sees nothing of it.
        $this->s->actingAs($this->s->clientBUser);
        $this->api('GET', "portal/visits/$id")->assertNotFound();
    }

    public function test_start_is_idempotent_on_client_uuid(): void
    {
        $uuid = (string) Str::uuid();
        $first = $this->startVisit($uuid);
        $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => $uuid])->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame(1, Visit::query()->count());
    }

    public function test_two_visits_cannot_run_for_one_assignment(): void
    {
        $this->startVisit();
        $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'visit_in_progress');
    }

    public function test_check_in_far_from_the_location_is_rejected_and_recorded(): void
    {
        $id = $this->startVisit();
        $this->api('POST', "visits/$id/check-in", Scenario::gps(5000))->assertStatus(422)->assertJsonPath('code', 'gps_rejected');
        $this->assertSame('scheduled', Visit::query()->find($id)->status->value);
        $this->assertDatabaseHas('visit_events', ['visit_id' => $id, 'type' => 'checkin_rejected']);
        // The agent can retry from the right place.
        $this->api('POST', "visits/$id/check-in", Scenario::gps(10))->assertOk();
    }

    public function test_slightly_outside_geofence_is_allowed_but_flagged(): void
    {
        $id = $this->startVisit();
        $this->api('POST', "visits/$id/check-in", Scenario::gps(160))->assertOk()->assertJsonPath('data.gps_outcome', 'warning');
    }

    public function test_cannot_check_out_before_check_in_or_submit_before_check_out(): void
    {
        $id = $this->startVisit();
        $this->api('POST', "visits/$id/check-out", Scenario::gps())->assertStatus(409);
        $this->api('POST', "visits/$id/submit")->assertStatus(409);
    }

    public function test_submission_requires_required_answers_and_conditional_logic_applies(): void
    {
        $id = $this->startVisit();
        $q = $this->s->questionIds();
        $this->api('POST', "visits/$id/check-in", Scenario::gps())->assertOk();
        $this->api('POST', "visits/$id/check-out", Scenario::gps())->assertOk();
        $this->api('POST', "visits/$id/submit")->assertStatus(422);   // "Stocked?" is required

        $this->api('PUT', "visits/$id/responses", ['answers' => [$q['Stocked?'] => false]])->assertOk();
        $this->api('POST', "visits/$id/submit")->assertStatus(422);   // "Why not?" becomes required when stocked = false

        $this->api('PUT', "visits/$id/responses", ['answers' => [$q['Why not?'] => 'Out of stock at distributor']])->assertOk();
        $this->api('POST', "visits/$id/submit")->assertOk();
    }

    public function test_answers_are_validated_server_side(): void
    {
        $id = $this->startVisit();
        $q = $this->s->questionIds();
        $this->api('POST', "visits/$id/check-in", Scenario::gps())->assertOk();
        $this->api('PUT', "visits/$id/responses", ['answers' => [$q['Facings'] => 999]])->assertStatus(422);
        $this->api('PUT', "visits/$id/responses", ['answers' => [$q['Facings'] => 'abc']])->assertStatus(422);
        $this->api('PUT', "visits/$id/responses", ['answers' => [(string) Str::uuid() => 'x']])->assertStatus(422);
    }

    public function test_submitted_visits_are_immutable(): void
    {
        $id = $this->startVisit();
        $this->completeVisit($id);
        $this->api('POST', "visits/$id/submit")->assertOk();

        $q = $this->s->questionIds();
        $this->api('PUT', "visits/$id/responses", ['answers' => [$q['Facings'] => 1]])->assertStatus(409);
        $this->api('POST', "visits/$id/sales", ['items' => [['product_id' => $this->s->productA->id, 'quantity' => 1]]])->assertStatus(409);
        $this->api('POST', "visits/$id/photos", ['file' => UploadedFile::fake()->image('late.jpg')])->assertStatus(409);
        $this->api('POST', "visits/$id/submit")->assertStatus(409);
    }

    public function test_rejection_requires_a_reason_and_reopens_the_assignment(): void
    {
        $id = $this->startVisit();
        $this->completeVisit($id);
        $this->api('POST', "visits/$id/submit")->assertOk();

        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', "visits/$id/reject", [])->assertStatus(422);
        $this->api('POST', "visits/$id/reject", ['note' => 'Photo is blurry'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('in_progress', $this->s->assignmentA->refresh()->status->value);

        // Rejected is terminal, and a corrected visit can be started.
        $this->api('POST', "visits/$id/approve")->assertStatus(409);
        $this->s->actingAs($this->s->agent);
        $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => (string) Str::uuid()])->assertCreated();
    }

    public function test_sales_totals_are_computed_by_the_server_and_products_must_belong_to_the_campaign(): void
    {
        $id = $this->startVisit();
        $this->api('POST', "visits/$id/check-in", Scenario::gps())->assertOk();
        // Client-supplied totals are ignored.
        $res = $this->api('POST', "visits/$id/sales", ['total_value' => 1, 'items' => [['product_id' => $this->s->productA->id, 'quantity' => 2, 'unit_price' => 100]]])->assertCreated();
        $this->assertSame('200.00', $res->json('data.total_value'));

        $foreign = \App\Models\Product::query()->create(['client_id' => $this->s->clientB->id, 'name' => 'Other', 'unit_price' => 5]);
        $this->api('POST', "visits/$id/sales", ['items' => [['product_id' => $foreign->id, 'quantity' => 1]]])->assertStatus(422);
    }

    public function test_sale_replays_do_not_duplicate(): void
    {
        $id = $this->startVisit();
        $this->api('POST', "visits/$id/check-in", Scenario::gps())->assertOk();
        $payload = ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->s->productA->id, 'quantity' => 1]]];
        $a = $this->api('POST', "visits/$id/sales", $payload)->json('data.id');
        $b = $this->api('POST', "visits/$id/sales", $payload)->json('data.id');
        $this->assertSame($a, $b);
        $this->assertSame(1, \App\Models\Sale::query()->where('visit_id', $id)->count());
    }

    public function test_reviewers_cannot_review_their_own_visit(): void
    {
        // Make the supervisor also an agent's equivalent: simulate by giving the visit to the supervisor.
        $v = Visit::query()->create([
            'assignment_id' => $this->s->assignmentA->id, 'campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->supervisor->id,
            'location_id' => $this->s->locationA->id, 'status' => 'submitted', 'visit_date' => now()->toDateString(),
        ]);
        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', "visits/{$v->id}/approve")->assertStatus(403);
    }
}
