<?php

namespace Tests\Feature;

use App\Domain\Assignments\JourneyPlanService;
use App\Domain\Projects\ProjectSettings;
use App\Models\Assignment;
use App\Models\Location;
use App\Models\ProjectTemplate;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** Project templates, permanent/reliever agents and fixed journey plans. */
class ProjectSetupTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    private function template(string $slug): ProjectTemplate
    {
        return ProjectTemplate::query()->where('slug', $slug)->firstOrFail();
    }

    public function test_the_five_built_in_templates_exist(): void
    {
        $this->assertEqualsCanonicalizing(['merchandising', 'activation', 'roadshow', 'general_trade', 'custom', 'sales_rep'], ProjectTemplate::query()->where('is_system', true)->pluck('slug')->all());
    }

    public function test_built_in_templates_are_read_only_but_can_be_copied(): void
    {
        $this->s->actingAs($this->s->ops);
        $t = $this->template('merchandising');
        $this->api('PATCH', "project-templates/{$t->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->api('DELETE', "project-templates/{$t->id}")->assertForbidden();

        $copy = $this->api('POST', 'project-templates', ['name' => 'Key accounts', 'settings' => ['visit_label' => 'Stop', 'min_photos' => 4, 'bogus' => 'x']])->assertCreated()->json('data');
        $this->assertSame('Stop', $copy['settings']['visit_label']);
        $this->assertSame(4, $copy['settings']['min_photos']);
        $this->assertArrayNotHasKey('bogus', $copy['settings']);
        $this->api('PATCH', "project-templates/{$copy['id']}", ['name' => 'Key accounts v2'])->assertOk();
    }

    public function test_a_campaign_takes_its_setup_from_the_chosen_template(): void
    {
        $this->s->actingAs($this->s->ops);
        $t = $this->template('activation');
        $c = $this->api('POST', 'campaigns', ['client_id' => $this->s->clientA->id, 'code' => 'ACT-1', 'name' => 'Launch', 'template_id' => $t->id])->assertCreated()->json('data');
        $this->assertSame('Activation', $c['settings']['visit_label']);

        // ...and it can be tuned for this project without touching the template.
        $this->api('PATCH', "campaigns/{$c['id']}", ['settings' => ['min_photos' => 1]])->assertOk()->assertJsonPath('data.settings.min_photos', 1);
        $this->assertSame(2, $this->template('activation')->settings['min_photos']);
    }

    public function test_who_may_add_outlets_is_a_switch_on_each_agent(): void
    {
        $base = fn () => ['location_id' => (string) Str::uuid(), 'campaign_id' => $this->s->campaignA->id, 'name' => 'Shop '.Str::random(5)];
        $this->s->actingAs($this->s->agent);

        \DB::table('agent_profiles')->where('user_id', $this->s->agent->id)->update(['can_add_outlets' => false]);
        $this->api('POST', 'locations/field', $base())->assertForbidden();

        \DB::table('agent_profiles')->where('user_id', $this->s->agent->id)->update(['can_add_outlets' => true]);
        $this->api('POST', 'locations/field', $base())->assertCreated();
    }

    public function test_the_switch_is_set_per_person_by_the_office(): void
    {
        $this->s->actingAs($this->s->ops);
        $this->api('PATCH', "users/{$this->s->otherAgent->id}", ['can_add_outlets' => true])->assertOk()->assertJsonPath('data.agent_profile.can_add_outlets', true);
        $this->assertTrue((bool) \DB::table('agent_profiles')->where('user_id', $this->s->otherAgent->id)->value('can_add_outlets'));
        $this->api('PATCH', "users/{$this->s->otherAgent->id}", ['can_add_outlets' => false])->assertOk()->assertJsonPath('data.agent_profile.can_add_outlets', false);
        $this->s->actingAs($this->s->agent);
        $this->api('PATCH', "users/{$this->s->otherAgent->id}", ['can_add_outlets' => true])->assertForbidden();
    }

    public function test_sync_tells_the_phone_which_campaigns_the_agent_may_add_outlets_to(): void
    {
        $this->s->campaignA->update(['settings' => ['min_photos' => 2]]);
        \DB::table('agent_profiles')->where('user_id', $this->s->agent->id)->update(['can_add_outlets' => false]);
        $this->s->actingAs($this->s->agent);
        $c = collect($this->api('GET', 'sync/bootstrap')->assertOk()->json('data.campaigns'))->firstWhere('id', $this->s->campaignA->id);
        $this->assertFalse($c['can_add_outlet']);
        $this->assertNotEmpty($c['add_outlet_denial']);
        $this->assertSame(2, $c['settings']['min_photos']);
        $a = collect($this->api('GET', 'sync/bootstrap')->json('data.assignments'))->firstWhere('campaign_id', $this->s->campaignA->id);
        $this->assertSame(2, $a['campaign']['settings']['min_photos']);
    }

    public function test_minimum_photos_must_be_met_before_submitting(): void
    {
        $this->s->campaignA->update(['settings' => ['min_photos' => 1]]);
        $this->s->actingAs($this->s->agent);
        $v = $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->api('POST', "visits/$v/check-in", Scenario::gps())->assertOk();
        $this->api('POST', "visits/$v/check-out", Scenario::gps())->assertOk();
        $this->api('POST', "visits/$v/submit")->assertStatus(422)->assertJsonFragment(['evidence.minimum' => ['This project needs at least 1 photo(s) per visit; there are 0.']]);
    }

    /* ------------------------------------------------------------ journey plans */

    private function plan(): array
    {
        $this->s->actingAs($this->s->manager);

        return $this->api('POST', 'journey-plans', ['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id, 'name' => 'Westlands round'])->assertCreated()->json('data');
    }

    public function test_a_journey_plan_generates_visits_for_the_agent_and_is_idempotent(): void
    {
        $plan = $this->plan();
        $weekdays = [1, 2, 3, 4, 5, 6, 7];
        $r = $this->api('PUT', "journey-plans/{$plan['id']}/stops", ['stops' => [['location_id' => $this->s->locationA->id, 'weekdays' => $weekdays]]])->assertOk();
        $r->assertJsonPath('data.stops.0.weekdays', $weekdays);

        $n = Assignment::query()->where('agent_id', $this->s->agent->id)->where('source', 'journey_plan')->count();
        $this->assertGreaterThanOrEqual(14, $n);            // one per day over the window
        $this->assertSame(0, app(JourneyPlanService::class)->generateAll());   // second run creates nothing

        $this->s->actingAs($this->s->agent);
        $sync = $this->api('GET', 'sync/bootstrap')->json('data.assignments');
        $this->assertTrue(collect($sync)->contains(fn ($a) => $a['source'] === 'journey_plan'));
    }

    public function test_removing_a_stop_cancels_its_future_unstarted_visits(): void
    {
        $plan = $this->plan();
        $this->api('PUT', "journey-plans/{$plan['id']}/stops", ['stops' => [['location_id' => $this->s->locationA->id, 'weekdays' => [1, 2, 3, 4, 5, 6, 7]]]])->assertOk();
        $this->api('PUT', "journey-plans/{$plan['id']}/stops", ['stops' => []])->assertOk();
        $this->assertSame(0, Assignment::query()->where('source', 'journey_plan')->whereIn('status', ['pending', 'accepted'])->count());
    }

    public function test_plan_stops_must_be_outlets_of_the_campaign(): void
    {
        $plan = $this->plan();
        $this->s->actingAs($this->s->manager);
        $this->api('PUT', "journey-plans/{$plan['id']}/stops", ['stops' => [['location_id' => $this->s->locationB->id, 'weekdays' => [1]]]])->assertStatus(422);
    }

    public function test_one_plan_per_agent_and_campaign_and_agents_cannot_manage_plans(): void
    {
        $this->plan();
        $this->s->actingAs($this->s->manager);
        $this->api('POST', 'journey-plans', ['campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id])->assertStatus(409);
        $this->s->actingAs($this->s->agent);
        $this->api('GET', 'journey-plans')->assertForbidden();
    }

    public function test_pausing_a_plan_stops_new_visits(): void
    {
        $plan = $this->plan();
        $this->api('PUT', "journey-plans/{$plan['id']}/stops", ['stops' => [['location_id' => $this->s->locationA->id, 'weekdays' => [1, 2, 3, 4, 5, 6, 7]]]])->assertOk();
        $this->api('POST', "journey-plans/{$plan['id']}/status", ['status' => 'paused'])->assertOk();
        $this->assertSame(0, Assignment::query()->where('source', 'journey_plan')->whereIn('status', ['pending', 'accepted'])->count());
        $this->assertSame(0, app(JourneyPlanService::class)->generateAll());
    }

    public function test_settings_are_always_complete_and_safe(): void
    {
        $s = ProjectSettings::normalize(['review' => 'everything', 'min_photos' => 999, 'tasks' => ['sales' => false, 'hack' => true]]);
        $this->assertSame('exceptions', $s['review']);
        $this->assertArrayNotHasKey('outlet_adders', $s);
        $this->assertSame(50, $s['min_photos']);
        $this->assertFalse($s['tasks']['sales']);
        $this->assertArrayNotHasKey('hack', $s['tasks']);
        $this->assertTrue($s['tasks']['form']);
    }

    public function test_project_targets_are_stored_clean_and_shown_against_reports(): void
    {
        $t = ProjectSettings::normalize(['targets' => ['osa_pct' => 90, 'sos_pct' => 'abc', 'coverage_pct' => 250, 'visits_per_outlet' => 0]]);
        $this->assertSame(90.0, $t['targets']['osa_pct']);
        $this->assertNull($t['targets']['sos_pct']);
        $this->assertSame(100.0, $t['targets']['coverage_pct']);
        $this->assertNull($t['targets']['visits_per_outlet']);

        $this->s->actingAs($this->s->ops);
        $this->api('PATCH', "campaigns/{$this->s->campaignA->id}", ['settings' => ['targets' => ['osa_pct' => 80, 'coverage_pct' => 70]]])->assertOk()->assertJsonPath('data.settings.targets.osa_pct', 80);
        $r = $this->api('GET', 'reports/osa_by_outlet', ['campaign_id' => $this->s->campaignA->id])->assertOk();
        $this->assertSame(80, (int) $r->json('data.targets.osa_pct'));
        $this->assertContains('osa_gap', collect($r->json('data.columns'))->pluck('key')->all());
        $none = $this->api('GET', 'reports/osa_by_outlet')->assertOk();
        $this->assertNull($none->json('data.targets'));
    }

    public function test_one_outlet_can_run_a_different_activity_on_different_days(): void
    {
        $plan = $this->plan();
        $weekend = \App\Models\Activity::query()->create(['campaign_id' => $this->s->campaignA->id, 'name' => 'Weekend activation', 'status' => 'active']);
        $this->api('PUT', "journey-plans/{$plan['id']}/stops", ['stops' => [
            ['location_id' => $this->s->locationA->id, 'weekdays' => [1, 2, 3, 4], 'activity_id' => $this->s->activityA->id],
            ['location_id' => $this->s->locationA->id, 'weekdays' => [5, 6, 7], 'activity_id' => $weekend->id],
        ]])->assertOk();
        $days = collect($this->api('GET', "journey-plans/{$plan['id']}")->assertOk()->json('data.stops.0.days'));
        $this->assertCount(7, $days);
        $this->assertSame($this->s->activityA->id, $days->firstWhere('weekday', 2)['activity_id']);
        $this->assertSame($weekend->id, $days->firstWhere('weekday', 6)['activity_id']);
        $this->assertSame(1, (int) $this->api('GET', 'journey-plans')->json('data.0.outlets_count'));
    }

    public function test_the_planner_can_see_who_else_covers_an_outlet(): void
    {
        $plan = $this->plan();
        $this->api('PUT', "journey-plans/{$plan['id']}/stops", ['stops' => [['location_id' => $this->s->locationA->id, 'weekdays' => [1]]]])->assertOk();
        $owners = $this->api('GET', 'journey-plans/owners', ['campaign_id' => $this->s->campaignA->id])->assertOk()->json('data');
        $this->assertSame([$this->s->agent->name], $owners[$this->s->locationA->id]);
        $this->assertSame([], $this->api('GET', 'journey-plans/owners', ['campaign_id' => $this->s->campaignA->id, 'except_plan' => $plan['id']])->json('data'));
    }
}

