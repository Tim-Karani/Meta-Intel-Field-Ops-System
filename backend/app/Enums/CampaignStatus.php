<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Planned = 'planned';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Planned, self::Archived],
            self::Planned => [self::Active, self::Archived],
            self::Active => [self::Paused, self::Completed],
            self::Paused => [self::Active, self::Completed],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function acceptsAssignments(): bool
    {
        return in_array($this, [self::Planned, self::Active], true);
    }
}
