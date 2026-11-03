<?php

namespace Tests\Unit;

use App\Enums\Permissions;
use App\Enums\Role;
use PHPUnit\Framework\TestCase;

/** The default role -> permission map is the heart of RBAC, so its invariants are pinned here. */
class PermissionsTest extends TestCase
{
    public function test_every_role_permission_is_a_known_slug(): void
    {
        $known = Permissions::flat();
        foreach (Role::cases() as $role) {
            foreach (Permissions::forRole($role) as $slug) {
                $this->assertContains($slug, $known, "{$role->value} has unknown permission $slug");
            }
        }
    }

    public function test_only_system_admin_manages_the_system(): void
    {
        foreach (Role::cases() as $role) {
            $has = in_array('system.manage', Permissions::forRole($role), true);
            $this->assertSame($role === Role::SystemAdmin, $has, $role->value);
        }
    }

    public function test_field_agents_cannot_review_approve_or_administer(): void
    {
        $agent = Permissions::forRole(Role::FieldAgent);
        foreach (['visits.review', 'visits.approve', 'visits.reject', 'users.view', 'users.create', 'campaigns.edit', 'clients.view', 'reports.view', 'audit.view', 'evidence.review'] as $p) {
            $this->assertNotContains($p, $agent, $p);
        }
        foreach (['visits.create', 'visits.submit', 'attendance.create', 'evidence.upload', 'assignments.respond'] as $p) {
            $this->assertContains($p, $agent, $p);
        }
    }

    public function test_only_people_who_visit_outlets_hold_field_work_permissions(): void
    {
        foreach (Role::cases() as $role) {
            $has = array_intersect(Permissions::FIELD_WORK, Permissions::forRole($role)) === Permissions::FIELD_WORK;
            $this->assertSame(in_array($role, [Role::FieldAgent, Role::TeamLeader], true), $has, $role->value);
        }
        $this->assertNotContains('assignments.respond', Permissions::forRole(Role::SystemAdmin));
    }

    public function test_client_users_only_get_the_portal(): void
    {
        $this->assertEqualsCanonicalizing(['portal.view', 'notifications.view'], Permissions::forRole(Role::ClientUser));
    }

    public function test_analyst_is_read_only_except_exports(): void
    {
        foreach (Permissions::forRole(Role::Analyst) as $slug) {
            $this->assertMatchesRegularExpression('/\.(view)$|^reports\.export$/', $slug, $slug);
        }
    }

    public function test_scope_types(): void
    {
        $this->assertSame('all', Role::SystemAdmin->scopeType());
        $this->assertSame('own', Role::FieldAgent->scopeType());
        $this->assertSame('client', Role::ClientUser->scopeType());
        $this->assertSame('team', Role::Supervisor->scopeType());
        $this->assertSame('team', Role::TeamLeader->scopeType());
    }

    public function test_team_leader_runs_a_team_and_nothing_else(): void
    {
        $tl = Permissions::forRole(Role::TeamLeader);
        foreach (['assignments.create', 'visits.approve', 'attendance.view', 'users.view', 'agents.track', 'journey.review', 'stock.manage'] as $p) {
            $this->assertContains($p, $tl, $p);
        }
        // Least privilege: no master data, no company reports, no admin, no payroll.
        foreach (['users.create', 'campaigns.create', 'campaigns.edit', 'system.manage', 'audit.view', 'reports.view', 'reports.export', 'reports.schedule', 'clients.view',
            'products.view', 'forms.view', 'territories.view', 'teams.view', 'sales.view', 'osa.view', 'merchandising.view', 'payroll.view', 'locations.manage'] as $p) {
            $this->assertNotContains($p, $tl, $p);
        }
        $this->assertContains('reports.schedule', Permissions::forRole(Role::Supervisor));
    }

    public function test_every_role_holds_only_what_its_job_needs(): void
    {
        $admin = Permissions::forRole(Role::SystemAdmin);
        $this->assertNotContains('payroll.manage', $admin, 'payroll belongs to HR');
        $this->assertContains('payroll.manage', Permissions::forRole(Role::Hr));

        // Executives only read results; they never touch operational data or administration.
        foreach (Permissions::forRole(Role::Executive) as $slug) {
            $this->assertMatchesRegularExpression('/\.(view)$|^reports\.export$|^agents\.track$/', $slug, $slug);
        }
        $this->assertNotContains('sales.view', Permissions::forRole(Role::Executive));

        // Field agents do their own work only.
        $agent = Permissions::forRole(Role::FieldAgent);
        foreach (['clients.view', 'campaigns.view', 'locations.view', 'products.view', 'forms.view', 'reports.view', 'users.view', 'stock.view', 'agents.track'] as $p) {
            $this->assertNotContains($p, $agent, $p);
        }
        $this->assertContains('journey.request', $agent);

        // Only people who run the field team issue stock; analysts only look.
        $this->assertNotContains('stock.manage', Permissions::forRole(Role::Analyst));
        $this->assertNotContains('stock.view', Permissions::forRole(Role::Executive));
    }

    public function test_permission_slugs_are_unique(): void
    {
        $flat = Permissions::flat();
        $this->assertSame(count($flat), count(array_unique($flat)));
    }
}
