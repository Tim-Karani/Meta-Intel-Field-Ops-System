<?php

namespace App\Enums;

enum Role: string
{
    case SystemAdmin = 'system_admin';
    case OperationsManager = 'operations_manager';
    case CampaignManager = 'campaign_manager';
    case Supervisor = 'supervisor';
    case TeamLeader = 'team_leader';
    case Analyst = 'analyst';
    case FieldAgent = 'field_agent';
    case ClientUser = 'client_user';
    case Executive = 'executive';
    case Hr = 'hr';

    public function label(): string
    {
        return match ($this) {
            self::SystemAdmin => 'System Administrator',
            self::OperationsManager => 'Agency / Operations Manager',
            self::CampaignManager => 'Project Manager',
            self::Supervisor => 'Supervisor',
            self::TeamLeader => 'Team Leader',
            self::Analyst => 'Analyst',
            self::FieldAgent => 'Field Agent',
            self::ClientUser => 'Authorized Client User',
            self::Executive => 'Executive',
            self::Hr => 'HR / Payroll',
        };
    }

    /** How far a role's data visibility reaches. See AccessScope. */
    public function scopeType(): string
    {
        return match ($this) {
            self::SystemAdmin, self::OperationsManager, self::Executive, self::Hr => 'all',
            self::CampaignManager, self::Analyst => 'campaigns',
            self::Supervisor, self::TeamLeader => 'team',
            self::FieldAgent => 'own',
            self::ClientUser => 'client',
        };
    }
}
