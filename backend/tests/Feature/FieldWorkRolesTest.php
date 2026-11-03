<?php

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** v1.3.2: supervision roles do not carry field-work permissions. */
class FieldWorkRolesTest extends DatabaseTestCase
{
    public function test_admin_has_no_field_work_permission_but_agent_has(): void
    {
        $s = Scenario::build();
        $this->assertFalse($s->admin->hasPermission('assignments.respond'));
        $this->assertTrue($s->admin->hasPermission('system.manage'));
        $this->assertTrue($s->agent->hasPermission('assignments.respond'));
    }
}
