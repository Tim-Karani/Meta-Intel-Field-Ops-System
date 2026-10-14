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
    }

    public function test_permission_slugs_are_unique(): void
    {
        $flat = Permissions::flat();
        $this->assertSame(count($flat), count(array_unique($flat)));
    }
}
