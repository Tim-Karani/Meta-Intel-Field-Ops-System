<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportService;
use App\Models\ReportExport;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

class ReportsTest extends DatabaseTestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
    }

    public function test_all_thirteen_report_types_run_for_an_administrator(): void
    {
        $this->s->actingAs($this->s->admin);
        $types = $this->api('GET', 'reports')->assertOk()->json('data');
        $this->assertCount(13, $types);
        foreach ($types as $t) {
            $r = $this->api('GET', 'reports/'.$t['type'])->assertOk()->json('data');
            $this->assertArrayHasKey('columns', $r, $t['type']);
            $this->assertArrayHasKey('rows', $r, $t['type']);
        }
        $this->api('GET', 'reports/not_a_report')->assertNotFound();
    }

    public function test_reports_respect_scope(): void
    {
        $this->s->actingAs($this->s->manager);   // linked to campaign A only
        $rows = $this->api('GET', 'reports/campaign_performance')->assertOk()->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('CAMP-A', json_encode($rows));
        $this->assertStringNotContainsString('CAMP-B', json_encode($rows));
    }

    public function test_filters_are_validated(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->api('GET', 'reports/sales', ['date_from' => 'yesterday'])->assertStatus(422);
        $this->api('GET', 'reports/sales', ['campaign_id' => "1' OR '1'='1"])->assertStatus(422);
        $this->api('GET', 'reports/sales', ['date_from' => '2026-02-01', 'date_to' => '2026-01-01'])->assertStatus(422);
    }

    public function test_export_is_permissioned_audited_and_formula_safe(): void
    {
        $this->s->actingAs($this->s->admin);
        $this->s->campaignA->update(['name' => '=cmd|calc']);

        $res = $this->get('/api/v1/reports/campaign_performance/export', ['Accept' => 'text/csv'])->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringNotContainsString(',=cmd|calc', $csv);
        $this->assertSame(1, ReportExport::query()->where('user_id', $this->s->admin->id)->where('report_type', 'campaign_performance')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported', 'actor_id' => $this->s->admin->id]);

        // Roles without reports.export are refused and nothing is logged.
        foreach ([$this->s->agent, $this->s->clientAUser] as $user) {
            $this->s->actingAs($user);
            $this->get('/api/v1/reports/campaign_performance/export')->assertForbidden();
        }
        $this->assertSame(1, ReportExport::query()->count());
    }

    public function test_client_reports_contain_approved_visits_only_and_no_agent_columns(): void
    {
        $this->s->actingAs($this->s->clientAUser);
        $this->api('GET', 'reports/sales')->assertForbidden();   // internal route
        $r = $this->api('GET', 'portal/reports/campaign_performance')->assertOk()->json('data');
        $this->assertTrue($r['approved_only']);
        $this->api('GET', 'portal/reports/agent_performance')->assertNotFound();
    }

    public function test_service_rejects_unknown_types(): void
    {
        $this->expectException(\App\Support\DomainRuleException::class);
        app(ReportService::class)->run('nope', $this->s->admin);
    }
}
