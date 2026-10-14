<?php

namespace App\Enums;

enum VisitStatus: string
{
    case Scheduled = 'scheduled';
    case Started = 'started';
    case Completed = 'completed';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Scheduled => [self::Started],
            self::Started => [self::Completed],
            self::Completed => [self::Submitted],
            self::Submitted => [self::UnderReview, self::Approved, self::Rejected],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved, self::Rejected => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Started, self::Completed], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Scheduled, self::Started, self::Completed], true);
    }

    public function isLocked(): bool
    {
        return !$this->isActive();
    }
}
