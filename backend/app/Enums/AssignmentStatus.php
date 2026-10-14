<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Accepted, self::InProgress, self::Overdue, self::Cancelled],
            self::Accepted => [self::InProgress, self::Overdue, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Overdue => [self::InProgress, self::Cancelled],
            self::Completed => [self::InProgress],   // only when a submitted visit is rejected
            self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Accepted, self::InProgress, self::Overdue], true);
    }
}
