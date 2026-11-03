<?php

namespace Tests\Feature;

use App\Domain\Assignments\JourneyPlanService;
use App\Enums\Role as RoleEnum;
use App\Models\AgentProfile;
use App\Models\Attendance;
use App\Models\JourneyPlan;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** v1.4: executive summary, live agents + scoring, device ping, journey-plan change requests, payroll. */
class ExecutiveTrackingPayrollTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_executive_summary_shape_and_access(): void
    {
        $this->s->actingAs($this->s->ops);
        $res = $this->api('GET', 'executive/summary')->assertOk();
        $res->assertJsonStructure(['data' => ['month', 'coverage' => [['month', 'visited', 'network', 'pct']], 'people' => ['active_today', 'total', 'teams'], 'journey_plan' => ['total', 'items'], 'shelf' => ['osa_brands', 'sos_categories']]]);
        $this->assertCount(3, $res->json('data.coverage'));
        $this->s->actingAs($this->s->agent);
        $this->api('GET', 'executive/summary')->assertForbidden();
        $this->s->actingAs($this->s->clientAUser);
        $this->api('GET', 'executive/summary')->assertForbidden();
    }

    public function test_hr_and_executive_roles_have_the_right_reach(): void
    {
        $hr = Scenario::user('HR', RoleEnum::Hr);
        $exec = Scenario::user('Exec', RoleEnum::Executive);
        $this->assertTrue($hr->hasPermission('payroll.manage'));
        $this->assertFalse($hr->hasPermission('visits.approve'));
        $this->assertTrue($exec->hasPermission('reports.view'));
        $this->assertFalse($exec->hasPermission('payroll.manage'));
        $this->s->actingAs($exec);
        $this->api('GET', 'executive/summary')->assertOk();
        $this->api('POST', 'payroll-runs', ['name' => 'x', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'])->assertForbidden();
    }

    public function test_ping_makes_the_agent_show_as_in_app_and_supervisor_sees_only_their_team(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('POST', 'agent/ping', ['latitude' => Scenario::LAT, 'longitude' => Scenario::LNG, 'accuracy_m' => 10, 'battery_pct' => 67, 'device_model' => 'Test Phone', 'os_version' => 'Android 15'])->assertOk();
        $this->s->actingAs($this->s->supervisor);
        $rows = $this->api('GET', 'agents/live')->assertOk()->json('data.data');
        $names = array_column($rows, 'name');
        $this->assertContains('Agent One', $names);
        $this->assertNotContains('Agent Two', $names);
        $me = collect($rows)->firstWhere('name', 'Agent One');
        $this->assertSame('in_app', $me['status']);
        $this->assertSame(67, $me['battery_pct']);
        $detail = $this->api('GET', "agents/{$me['id']}/overview")->assertOk()->json('data');
        $this->assertSame('Test Phone', $detail['device_model']);
        $this->assertNotEmpty($detail['timeline']);
        $this->api('GET', "agents/{$this->s->otherAgent->id}/overview")->assertNotFound();
    }

    public function test_agent_cannot_use_the_live_view(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('GET', 'agents/live')->assertForbidden();
    }

    private function plan(): JourneyPlan
    {
        $plan = JourneyPlan::query()->create(['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'name' => 'Route', 'status' => 'active']);
        app(JourneyPlanService::class)->saveStops($plan, [['location_id' => $this->s->locationA->id, 'weekdays' => [1, 3]]], $this->s->ops);

        return $plan->refresh();
    }

    public function test_jp_change_request_is_approved_and_updates_the_route(): void
    {
        $plan = $this->plan();
        $this->s->actingAs($this->s->agent);
        $id = $this->api('POST', 'jp-requests', ['journey_plan_id' => $plan->id, 'type' => 'move', 'location_id' => $this->s->locationA->id, 'weekday' => 3, 'to_weekday' => 5, 'reason' => 'Store closed on Wednesdays', 'latitude' => Scenario::LAT, 'longitude' => Scenario::LNG])
            ->assertCreated()->json('data.id');
        $this->api('POST', 'jp-requests', ['journey_plan_id' => $plan->id, 'type' => 'move', 'location_id' => $this->s->locationA->id, 'weekday' => 3, 'to_weekday' => 5, 'reason' => 'Store closed on Wednesdays'])->assertStatus(409);
        $this->api('POST', "jp-requests/$id/approve")->assertForbidden();

        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', "jp-requests/$id/approve", ['note' => 'ok'])->assertOk()->assertJsonPath('data.status', 'approved');
        $days = $plan->stops()->where('location_id', $this->s->locationA->id)->pluck('weekday')->map(fn ($d) => (int) $d)->sort()->values()->all();
        $this->assertSame([1, 5], $days);
        $this->api('POST', "jp-requests/$id/approve")->assertStatus(409);
    }

    public function test_jp_rejection_needs_a_reason_and_other_agents_cannot_see_the_request(): void
    {
        $plan = $this->plan();
        $this->s->actingAs($this->s->agent);
        $id = $this->api('POST', 'jp-requests', ['journey_plan_id' => $plan->id, 'type' => 'remove', 'location_id' => $this->s->locationA->id, 'weekday' => 1, 'reason' => 'Outlet has closed down'])->assertCreated()->json('data.id');
        $this->s->actingAs($this->s->otherAgent);
        $this->assertCount(0, $this->api('GET', 'jp-requests')->assertOk()->json('data'));
        $this->s->actingAs($this->s->supervisor);
        $this->api('POST', "jp-requests/$id/reject")->assertStatus(422);
        $this->api('POST', "jp-requests/$id/reject", ['note' => 'Still open, keep it'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(2, $plan->stops()->count());
    }

    public function test_payroll_run_prices_attendance_and_locks_after_approval(): void
    {
        AgentProfile::query()->where('user_id', $this->s->agent->id)->update(['pay_type' => 'daily', 'pay_rate' => 2000]);
        foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $d) {
            Attendance::query()->create(['user_id' => $this->s->agent->id, 'attendance_date' => $d, 'clock_in_at' => $d.' 07:00:00', 'work_started_at' => $d.' 08:00:00', 'work_ended_at' => $d.' 16:00:00', 'worked_minutes' => 480, 'outlets_visited' => 4, 'status' => 'present']);
        }
        Attendance::query()->create(['user_id' => $this->s->agent->id, 'attendance_date' => '2026-10-08', 'clock_in_at' => '2026-10-08 07:00:00', 'status' => 'present']);   // clocked in only: not paid
        $hr = Scenario::user('HR', RoleEnum::Hr);
        $this->s->actingAs($hr);
        $run = $this->api('POST', 'payroll-runs', ['name' => 'October', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'])->assertCreated()->json('data');
        $this->assertSame(1, $run['totals']['people']);
        $this->assertEquals(6000.0, $run['totals']['gross']);
        $line = $run['lines'][0];
        $this->assertSame(3, $line['days_worked']);
        $this->assertEquals(24.0, $line['hours_worked']);
        $after = $this->api('PATCH', "payroll-runs/{$run['id']}/lines/{$line['id']}", ['adjustment' => -500, 'adjustment_note' => 'Advance'])->assertOk()->json('data');
        $this->assertEquals(5500.0, $after['totals']['net']);
        $this->api('POST', "payroll-runs/{$run['id']}/recalculate")->assertOk();
        $this->assertEquals(5500.0, $this->api('GET', "payroll-runs/{$run['id']}")->json('data.totals.net'));   // the adjustment survives a recalculation
        $this->api('POST', "payroll-runs/{$run['id']}/approve")->assertOk();
        $this->api('PATCH', "payroll-runs/{$run['id']}/lines/{$line['id']}", ['adjustment' => 0])->assertStatus(409);
        $this->api('POST', 'payroll-runs', ['name' => 'Overlap', 'period_start' => '2026-10-15', 'period_end' => '2026-11-15'])->assertStatus(409);
        $this->api('POST', "payroll-runs/{$run['id']}/paid")->assertOk()->assertJsonPath('data.status', 'paid');
        $csv = $this->api('GET', "payroll-runs/{$run['id']}/export")->assertOk()->getContent();
        $this->assertStringContainsString('Agent One', $csv);
    }

    public function test_agent_cannot_see_payroll(): void
    {
        $this->s->actingAs($this->s->agent);
        $this->api('GET', 'payroll-runs')->assertForbidden();
    }
}
