<?php

namespace Tests\Feature;

use App\Domain\Reports\ScheduledReportService;
use App\Enums\Role as RoleEnum;
use App\Models\AgentProfile;
use App\Models\Attendance;
use App\Models\Assignment;
use App\Models\ReportSchedule;
use App\Models\Visit;
use App\Domain\Assignments\AssignmentService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** v1.3: Team Leader hierarchy, check-in/out photos, 200 m rule, worked time, attendance board, scheduled reports. */
class FieldRulesTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    private function startVisit(): string
    {
        return $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => (string) Str::uuid()])->assertSuccessful()->json('data.id');
    }

    private function photo(string $visit, string $kind): void
    {
        $this->api('POST', "visits/$visit/photos", ['kind' => $kind, 'source' => 'camera', 'file' => UploadedFile::fake()->image("$kind.jpg", 640, 480)])->assertCreated();
    }

    /* ----------------------------------------------------------------- 200 m rule */

    public function test_check_in_is_allowed_inside_and_refused_outside_200_metres(): void
    {
        $this->s->actingAs($this->s->agent);
        $v = $this->startVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(260))->assertStatus(422)->assertJsonPath('code', 'outside_radius');
        $this->api('POST', "visits/$v/check-in", Scenario::gps(150))->assertOk();
    }

    /* ----------------------------------------------------------------- proof photos */

    public function test_a_visit_cannot_be_submitted_without_check_in_and_check_out_photos(): void
    {
        $this->s->campaignA->update(['settings' => ['visit_photos' => true]]);
        $this->s->actingAs($this->s->agent);
        $v = $this->startVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(0))->assertOk();
        $this->api('PUT', "visits/$v/responses", ['answers' => [$this->s->questionIds()['Stocked?'] => true]])->assertOk();
        $this->api('POST', "visits/$v/check-out", Scenario::gps(0))->assertOk();

        $this->api('POST', "visits/$v/submit")->assertStatus(422)->assertJsonValidationErrors(['checkin_photo', 'checkout_photo']);
        $this->photo($v, 'checkin');
        $this->api('POST', "visits/$v/submit")->assertStatus(422)->assertJsonValidationErrors(['checkout_photo']);
        $this->photo($v, 'checkout');
        $this->api('POST', "visits/$v/submit")->assertOk();
    }

    public function test_proof_photos_must_come_from_the_camera(): void
    {
        $this->s->actingAs($this->s->agent);
        $v = $this->startVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(0))->assertOk();
        $this->api('POST', "visits/$v/photos", ['kind' => 'checkin', 'source' => 'gallery', 'file' => UploadedFile::fake()->image('a.jpg')])->assertStatus(422);
        $this->api('POST', "visits/$v/photos", ['kind' => 'checkin', 'file' => UploadedFile::fake()->image('a.jpg')])->assertStatus(422);
    }

    /* ----------------------------------------------------------------- worked time */

    public function test_clocking_in_is_not_time_worked_the_first_outlet_check_in_is(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('POST', 'attendance/clock-in', ['client_uuid' => (string) Str::uuid()])->assertCreated();
        $row = Attendance::query()->where('user_id', $this->s->agent->id)->firstOrFail();
        $this->assertNull($row->work_started_at, 'clock-in alone must not start working time');

        $v = $this->startVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(0))->assertOk();
        $row->refresh();
        $this->assertNotNull($row->work_started_at);

        $this->api('POST', "visits/$v/check-out", Scenario::gps(0))->assertOk();
        $row->refresh();
        $this->assertNotNull($row->work_ended_at);
        $this->assertSame(1, $row->outlets_visited);
        $this->assertNotNull($row->worked_minutes);
    }

    public function test_an_agent_who_never_clocked_in_still_gets_working_time_from_the_check_in(): void
    {
        $this->s->actingAs($this->s->agent);
        $v = $this->startVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(0))->assertOk();
        $this->assertDatabaseHas('attendance', ['user_id' => $this->s->agent->id, 'attendance_date' => now()->toDateString()]);
        $this->assertNotNull(Attendance::query()->where('user_id', $this->s->agent->id)->value('work_started_at'));
    }

    /* ----------------------------------------------------------------- hierarchy */

    private function hierarchy(): array
    {
        $tlNairobi = Scenario::user('TL Nairobi', RoleEnum::TeamLeader);
        $tlMombasa = Scenario::user('TL Mombasa', RoleEnum::TeamLeader);
        AgentProfile::query()->create(['user_id' => $tlNairobi->id, 'supervisor_id' => $this->s->supervisor->id, 'region' => 'Nairobi']);
        AgentProfile::query()->create(['user_id' => $tlMombasa->id, 'supervisor_id' => $this->s->supervisor->id, 'region' => 'Mombasa']);
        // agent (Nairobi) and otherAgent (Mombasa) both report to a Team Leader; only the Team Leaders report to the Supervisor.
        AgentProfile::query()->where('user_id', $this->s->agent->id)->update(['supervisor_id' => null, 'team_leader_id' => $tlNairobi->id]);
        AgentProfile::query()->where('user_id', $this->s->otherAgent->id)->update(['supervisor_id' => null, 'team_leader_id' => $tlMombasa->id]);

        return [$tlNairobi, $tlMombasa];
    }

    public function test_a_team_leader_sees_only_their_own_region_and_a_supervisor_sees_every_region(): void
    {
        [$tlNairobi, $tlMombasa] = $this->hierarchy();
        $this->s->campaignB->users()->sync([$this->s->manager->id]);
        $this->s->campaignA->users()->sync([$this->s->manager->id]);

        $names = fn () => collect($this->api('GET', 'assignments', ['per_page' => 100])->assertOk()->json('data'))->pluck('agent_id')->unique()->sort()->values()->all();

        $this->s->actingAs($tlNairobi);
        $this->assertSame([$this->s->agent->id], $names());

        $this->s->actingAs($tlMombasa);
        $this->assertSame([$this->s->otherAgent->id], $names());

        $this->s->actingAs($this->s->supervisor);
        $this->assertEqualsCanonicalizing([$this->s->agent->id, $this->s->otherAgent->id], $names());

        $this->s->actingAs($this->s->ops);
        $this->assertEqualsCanonicalizing([$this->s->agent->id, $this->s->otherAgent->id], $names());
    }

    public function test_the_attendance_board_is_for_leaders_only_and_respects_scope(): void
    {
        [$tlNairobi] = $this->hierarchy();
        $this->s->actingAs($this->s->agent);
        $this->api('GET', 'attendance/board')->assertForbidden();

        $this->s->actingAs($this->s->agent);
        $v = $this->startVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(0))->assertOk();

        $this->s->actingAs($tlNairobi);
        $board = $this->api('GET', 'attendance/board')->assertOk()->json('data');
        $this->assertSame([$this->s->agent->id], array_column($board['people'], 'user_id'));
        $this->assertSame('at_outlet', $board['people'][0]['state']);
        $this->assertCount(1, $board['checkins']);

        $this->s->actingAs($this->s->supervisor);
        // The Supervisor sees both regions: the two Team Leaders and the two agents under them.
        $this->assertCount(4, $this->api('GET', 'attendance/board')->assertOk()->json('data.people'));
    }

    public function test_a_team_leader_must_really_be_a_team_leader(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('POST', 'users', ['name' => 'New Agent', 'email' => 'new.agent@example.test', 'role' => 'field_agent', 'team_leader_id' => $this->s->manager->id])
            ->assertStatus(422)->assertJsonValidationErrors(['team_leader_id']);
        [$tl] = $this->hierarchy();
        $res = $this->api('POST', 'users', ['name' => 'New Agent', 'email' => 'new.agent@example.test', 'role' => 'field_agent', 'team_leader_id' => $tl->id])->assertCreated();
        $this->assertDatabaseHas('agent_profiles', ['user_id' => $res->json('data.id'), 'team_leader_id' => $tl->id, 'supervisor_id' => $this->s->supervisor->id, 'region' => 'Nairobi']);
    }

    /* ----------------------------------------------------------------- scheduled reports */

    public function test_schedule_due_logic(): void
    {
        $daily = new ReportSchedule(['frequency' => 'daily', 'send_time' => '07:00', 'is_active' => true]);
        $this->assertFalse(ScheduledReportService::isDue($daily, Carbon::parse('2026-03-02 06:59')));
        $this->assertTrue(ScheduledReportService::isDue($daily, Carbon::parse('2026-03-02 07:00')));
        $daily->last_run_at = Carbon::parse('2026-03-02 07:01');
        $this->assertFalse(ScheduledReportService::isDue($daily, Carbon::parse('2026-03-02 09:00')), 'sent once a day');
        $this->assertTrue(ScheduledReportService::isDue($daily, Carbon::parse('2026-03-03 07:05')));

        $weekly = new ReportSchedule(['frequency' => 'weekly', 'weekday' => 1, 'send_time' => '08:00', 'is_active' => true]);
        $this->assertTrue(ScheduledReportService::isDue($weekly, Carbon::parse('2026-03-02 08:00')), '2 March 2026 is a Monday');
        $this->assertFalse(ScheduledReportService::isDue($weekly, Carbon::parse('2026-03-03 08:00')));

        $monthly = new ReportSchedule(['frequency' => 'monthly', 'month_day' => 1, 'send_time' => '08:00', 'is_active' => true]);
        $this->assertTrue(ScheduledReportService::isDue($monthly, Carbon::parse('2026-04-01 08:00')));
        $this->assertFalse(ScheduledReportService::isDue($monthly, Carbon::parse('2026-04-02 08:00')));

        $paused = new ReportSchedule(['frequency' => 'daily', 'send_time' => '00:00', 'is_active' => false]);
        $this->assertFalse(ScheduledReportService::isDue($paused, Carbon::parse('2026-03-02 12:00')));
    }

    public function test_a_scheduled_report_is_emailed_to_each_recipient_as_a_csv(): void
    {
        Mail::fake();
        $this->s->actingAs($this->s->supervisor);
        $res = $this->api('POST', 'report-schedules', [
            'name' => 'Daily check-ins', 'report_type' => 'checkin', 'period' => 'today', 'frequency' => 'daily', 'send_time' => '07:00',
            'recipients' => ['client@example.com', 'boss@example.com', 'CLIENT@example.com'],
        ])->assertCreated();
        $this->assertCount(2, $res->json('data.recipients'), 'duplicate addresses collapse');

        $this->api('POST', 'report-schedules/'.$res->json('data.id').'/send')->assertOk();
        $this->assertDatabaseHas('report_runs', ['schedule_id' => $res->json('data.id'), 'status' => 'empty', 'recipient_count' => 2]);
    }

    public function test_schedules_are_private_to_their_owner_and_gated_by_permission(): void
    {
        $this->s->actingAs($this->s->analyst);
        $this->api('GET', 'report-schedules')->assertForbidden();

        $this->s->actingAs($this->s->supervisor);
        $id = $this->api('POST', 'report-schedules', ['name' => 'Mine', 'report_type' => 'osa', 'period' => 'yesterday', 'frequency' => 'daily', 'send_time' => '07:00', 'recipients' => ['a@example.com']])->assertCreated()->json('data.id');

        $this->s->actingAs($this->s->manager);
        $this->api('PATCH', "report-schedules/$id", ['name' => 'Stolen', 'report_type' => 'osa', 'period' => 'yesterday', 'frequency' => 'daily', 'send_time' => '07:00', 'recipients' => ['a@example.com']])->assertNotFound();

        $this->s->actingAs($this->s->admin);
        $this->api('GET', 'report-schedules')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_checkin_and_sos_reports_run(): void
    {
        $this->s->actingAs($this->s->agent);
        $v = $this->startVisit();
        $this->api('POST', "visits/$v/check-in", Scenario::gps(0))->assertOk();
        $this->api('POST', "visits/$v/merchandising", ['our_facings' => 6, 'total_facings' => 20, 'posm_present' => true])->assertSuccessful();

        $this->s->actingAs($this->s->ops);
        $this->api('GET', 'reports/checkin')->assertOk()->assertJsonPath('data.summary.check_ins', 1);
        $this->api('GET', 'reports/sos')->assertOk()->assertOk();
        $this->assertEquals(30, $this->api('GET', 'reports/sos')->json('data.summary.overall_sos'));
    }
}
