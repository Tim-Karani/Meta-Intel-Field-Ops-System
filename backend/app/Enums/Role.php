<?php

namespace App\Enums;

enum Role: string
{
    case SystemAdmin = 'system_admin';
    case OperationsManager = 'operations_manager';
    case CampaignManager = 'campaign_manager';
    case Supervisor = 'supervisor';
    case Analyst = 'analyst';
    case FieldAgent = 'field_agent';
    case ClientUser = 'client_user';

    public function label(): string
    {
        return match ($this) {
            self::SystemAdmin => 'System Administrator',
            self::OperationsManager => 'Agency / Operations Manager',
            self::CampaignManager => 'Campaign Manager',
            self::Supervisor => 'Supervisor',
            self::Analyst => 'Analyst',
            self::FieldAgent => 'Field Agent',
            self::ClientUser => 'Authorized Client User',
        };
    }

    /** How far a role's data visibility reaches. See AccessScope. */
    public function scopeType(): string
    {
        return match ($this) {
            self::SystemAdmin, self::OperationsManager => 'all',
            self::CampaignManager, self::Analyst => 'campaigns',
            self::Supervisor => 'team',
            self::FieldAgent => 'own',
            self::ClientUser => 'client',
        };
    }
}
