<?php

namespace App\Enums;

/** Single source of truth for permission slugs and the default role -> permission map. */
final class Permissions
{
    public const ALL = [
        'dashboard' => ['dashboard.view'],
        'clients' => ['clients.view', 'clients.create', 'clients.edit', 'clients.delete'],
        'campaigns' => ['campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.assign', 'campaigns.archive'],
        'activities' => ['activities.view', 'activities.create', 'activities.edit'],
        'users' => ['users.view', 'users.create', 'users.edit', 'users.disable'],
        'roles' => ['roles.view'],
        'teams' => ['teams.view', 'teams.manage'],
        'territories' => ['territories.view', 'territories.manage'],
        'locations' => ['locations.view', 'locations.manage', 'locations.capture'],
        'products' => ['products.view', 'products.manage'],
        'forms' => ['forms.view', 'forms.manage'],
        'assignments' => ['assignments.view', 'assignments.create', 'assignments.edit', 'assignments.respond'],
        'attendance' => ['attendance.view', 'attendance.create'],
        'visits' => ['visits.view', 'visits.create', 'visits.edit', 'visits.submit', 'visits.review', 'visits.approve', 'visits.reject'],
        'sales' => ['sales.view'],
        'osa' => ['osa.view'],
        'merchandising' => ['merchandising.view'],
        'evidence' => ['evidence.view', 'evidence.upload', 'evidence.review'],
        'issues' => ['issues.view', 'issues.create', 'issues.assign', 'issues.resolve'],
        'reports' => ['reports.view', 'reports.export', 'reports.schedule'],
        'notifications' => ['notifications.view'],
        'audit' => ['audit.view'],
        'system' => ['system.manage'],
        'portal' => ['portal.view'],
        'journey' => ['journey.request', 'journey.review'],
        'payroll' => ['payroll.view', 'payroll.manage'],
        'tracking' => ['agents.track'],
        'stock' => ['stock.view', 'stock.manage'],
    ];

    /** What only people who personally visit outlets need (Field Agents and Team Leaders). */
    public const FIELD_WORK = ['assignments.respond', 'attendance.create', 'visits.create', 'visits.edit', 'visits.submit', 'evidence.upload', 'locations.capture', 'journey.request'];

    /** @return list<string> */
    public static function flat(): array
    {
        return array_merge(...array_values(self::ALL));
    }

    /**
     * Least privilege: every role holds only what its day-to-day job needs. Nothing is granted "just in case"; a missing
     * permission is a feature that role never sees (the menu follows these grants).
     *
     * @return list<string>
     */
    public static function forRole(Role $role): array
    {
        // Read access to the operational data sets, for the roles that analyse the whole programme.
        $analysis = [
            'dashboard.view', 'clients.view', 'campaigns.view', 'activities.view', 'teams.view', 'territories.view',
            'locations.view', 'products.view', 'forms.view', 'assignments.view', 'attendance.view', 'visits.view',
            'sales.view', 'osa.view', 'merchandising.view', 'evidence.view', 'issues.view', 'reports.view', 'notifications.view', 'stock.view',
        ];
        // What anyone who looks after field agents needs: see the work, review it, handle problems, follow agents.
        $oversight = [
            'dashboard.view', 'campaigns.view', 'activities.view', 'locations.view', 'assignments.view', 'assignments.create', 'assignments.edit',
            'attendance.view', 'visits.view', 'visits.review', 'visits.approve', 'visits.reject', 'evidence.view', 'evidence.review',
            'issues.view', 'issues.create', 'issues.assign', 'issues.resolve', 'users.view', 'notifications.view', 'journey.review', 'agents.track', 'stock.view', 'stock.manage',
        ];
        $merge = fn (array ...$sets) => array_values(array_unique(array_merge(...$sets)));

        return match ($role) {
            // The administrator configures and supervises; they do not visit outlets and do not run payroll (that is HR's).
            Role::SystemAdmin => array_values(array_diff(self::flat(), self::FIELD_WORK, ['payroll.manage'])),
            Role::OperationsManager => $merge($analysis, $oversight, [
                'clients.create', 'clients.edit', 'clients.delete', 'campaigns.create', 'campaigns.edit', 'campaigns.assign', 'campaigns.archive',
                'activities.create', 'activities.edit', 'roles.view', 'teams.manage', 'territories.manage', 'locations.manage',
                'products.manage', 'forms.manage', 'reports.export', 'reports.schedule', 'audit.view',
            ]),
            // Sets up and runs their own projects; no company-wide administration.
            Role::CampaignManager => $merge($oversight, [
                'clients.view', 'teams.view', 'teams.manage', 'products.view', 'products.manage', 'forms.view', 'forms.manage', 'locations.manage',
                'campaigns.edit', 'campaigns.assign', 'activities.create', 'activities.edit', 'sales.view', 'osa.view', 'merchandising.view',
                'reports.view', 'reports.export', 'reports.schedule',
            ]),
            // Looks after a set of agents and team leaders. Reports for their area; no master data.
            Role::Supervisor => $merge($oversight, ['teams.view', 'reports.view', 'reports.export', 'reports.schedule']),
            // One region / small team: today's work and the people doing it. Nothing else.
            Role::TeamLeader => $merge($oversight, self::FIELD_WORK),   // also visits outlets themselves (never reviews their own visit)
            Role::Analyst => $merge($analysis, ['reports.export']),
            // Does the job in the field: their work, their attendance, their proof, their issues.
            Role::FieldAgent => [
                'dashboard.view', 'assignments.view', 'assignments.respond', 'attendance.view', 'attendance.create', 'visits.view', 'visits.create', 'visits.edit',
                'visits.submit', 'evidence.view', 'evidence.upload', 'issues.view', 'issues.create', 'locations.capture', 'journey.request', 'notifications.view',
            ],
            Role::ClientUser => ['portal.view', 'notifications.view'],
            // Portfolio view: results and where the people are. No editing, no operational data entry screens.
            Role::Executive => ['dashboard.view', 'clients.view', 'campaigns.view', 'reports.view', 'reports.export', 'agents.track', 'notifications.view'],
            // Attendance and payroll.
            Role::Hr => ['dashboard.view', 'users.view', 'attendance.view', 'reports.view', 'reports.export', 'agents.track', 'payroll.view', 'payroll.manage', 'notifications.view'],
        };
    }
}
