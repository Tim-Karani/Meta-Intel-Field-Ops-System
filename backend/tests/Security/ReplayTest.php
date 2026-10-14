<?php

namespace Tests\Security;

use App\Models\Attendance;
use App\Models\Issue;
use App\Models\Photo;
use App\Models\Sale;
use App\Models\Visit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** Duplicate offline submissions and replayed requests must never create duplicate or contradictory data. */
class ReplayTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->agent);
    }

    public function test_the_whole_offline_batch_can_be_replayed_safely(): void
    {
        $uuids = array_map(fn () => (string) Str::uuid(), range(1, 5));
        for ($round = 0; $round < 3; $round++) {
            $v = $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => $uuids[0]])->json('data.id');
            $round === 0 && $this->api('POST', "visits/$v/check-in", Scenario::gps())->assertOk();
            $this->api('POST', "visits/$v/sales", ['client_uuid' => $uuids[1], 'items' => [['product_id' => $this->s->productA->id, 'quantity' => 2]]])->assertSuccessful();
            $this->api('POST', "visits/$v/photos", ['client_uuid' => $uuids[2], 'file' => UploadedFile::fake()->image('p.jpg')])->assertSuccessful();
            $this->api('POST', 'issues', ['client_uuid' => $uuids[3], 'title' => 'Replayed', 'visit_id' => $v])->assertSuccessful();
            $this->api('POST', 'attendance/clock-in', ['client_uuid' => $uuids[4]])->assertSuccessful();
        }
        $this->assertSame(1, Visit::query()->count());
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(1, Photo::query()->count());
        $this->assertSame(1, Issue::query()->count());
        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_replaying_state_changing_calls_is_a_conflict_not_a_second_change(): void
    {
        $v = $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id])->json('data.id');
        $this->api('POST', "visits/$v/check-in", Scenario::gps())->assertOk();
        $this->api('POST', "visits/$v/check-in", Scenario::gps())->assertStatus(409);
        $this->api('POST', "visits/$v/check-out", Scenario::gps())->assertOk();
        $this->api('POST', "visits/$v/check-out", Scenario::gps())->assertStatus(409);
        $q = $this->s->questionIds();
        $this->api('PUT', "visits/$v/responses", ['answers' => [$q['Stocked?'] => true]])->assertOk();
        $this->api('POST', "visits/$v/submit")->assertOk();
        $this->api('POST', "visits/$v/submit")->assertStatus(409);

        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', "visits/$v/approve")->assertOk();
        $this->api('POST', "visits/$v/approve")->assertStatus(409);
        $this->api('POST', "visits/$v/reject", ['note' => 'changed my mind'])->assertStatus(409);
        $this->assertSame(1, \App\Models\VisitEvent::query()->where('visit_id', $v)->where('type', 'approved')->count());
    }

    public function test_client_uuid_cannot_be_used_to_hijack_another_users_record(): void
    {
        $uuid = (string) Str::uuid();
        $mine = $this->api('POST', 'issues', ['client_uuid' => $uuid, 'title' => 'Mine'])->assertCreated()->json('data.id');

        // The same uuid from a different user is a different record (uniqueness is per user), never a leak.
        $this->s->actingAs($this->s->otherAgent);
        $theirs = $this->api('POST', 'issues', ['client_uuid' => $uuid, 'title' => 'Theirs'])->assertCreated()->json('data.id');
        $this->assertNotSame($mine, $theirs);
    }

    public function test_an_old_token_cannot_be_replayed_after_logout(): void
    {
        $user = $this->s->agent;
        $token = $this->api('POST', 'auth/login', ['login' => $user->email, 'password' => Scenario::PASSWORD])->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/assignments')->assertStatus(401);
    }
}
