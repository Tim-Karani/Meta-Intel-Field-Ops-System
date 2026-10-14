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
        'locations' => ['locations.view', 'locations.manage'],
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
        'reports' => ['reports.view', 'reports.export'],
        'notifications' => ['notifications.view'],
        'audit' => ['audit.view'],
        'system' => ['system.manage'],
        'portal' => ['portal.view'],
    ];

    /** @return list<string> */
    public static function flat(): array
    {
        return array_merge(...array_values(self::ALL));
    }

    /** @return list<string> */
    public static function forRole(Role $role): array
    {
        $viewAll = [
            'dashboard.view', 'clients.view', 'campaigns.view', 'activities.view', 'teams.view', 'territories.view',
            'locations.view', 'products.view', 'forms.view', 'assignments.view', 'attendance.view', 'visits.view',
            'sales.view', 'osa.view', 'merchandising.view', 'evidence.view', 'issues.view', 'reports.view', 'notifications.view',
        ];
        $merge = fn (array ...$sets) => array_values(array_unique(array_merge(...$sets)));

        return match ($role) {
            Role::SystemAdmin => self::flat(),
            Role::OperationsManager => $merge($viewAll, [
                'clients.create', 'clients.edit', 'clients.delete', 'campaigns.create', 'campaigns.edit', 'campaigns.assign', 'campaigns.archive',
                'activities.create', 'activities.edit', 'users.view', 'roles.view', 'teams.manage', 'territories.manage', 'locations.manage',
                'products.manage', 'forms.manage', 'assignments.create', 'assignments.edit', 'visits.review', 'visits.approve', 'visits.reject',
                'evidence.review', 'issues.create', 'issues.assign', 'issues.resolve', 'reports.export', 'audit.view',
            ]),
            Role::CampaignManager => $merge($viewAll, [
                'campaigns.edit', 'campaigns.assign', 'activities.create', 'activities.edit', 'teams.manage', 'locations.manage', 'products.manage',
                'forms.manage', 'assignments.create', 'assignments.edit', 'visits.review', 'visits.approve', 'visits.reject', 'evidence.review',
                'issues.create', 'issues.assign', 'issues.resolve', 'reports.export',
            ]),
            Role::Supervisor => $merge($viewAll, [
                'teams.manage', 'assignments.create', 'assignments.edit', 'visits.review', 'visits.approve', 'visits.reject', 'evidence.review',
                'issues.create', 'issues.assign', 'issues.resolve', 'reports.export',
            ]),
            Role::Analyst => $merge($viewAll, ['reports.export']),
            Role::FieldAgent => [
                'assignments.view', 'assignments.respond', 'attendance.view', 'attendance.create', 'visits.view', 'visits.create', 'visits.edit',
                'visits.submit', 'evidence.view', 'evidence.upload', 'issues.view', 'issues.create', 'locations.view', 'forms.view', 'products.view',
                'notifications.view', 'dashboard.view',
            ],
            Role::ClientUser => ['portal.view', 'notifications.view'],
        };
    }
}
