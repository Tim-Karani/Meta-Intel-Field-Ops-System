<?php

namespace App\Enums;

enum IssueStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Open => [self::Assigned, self::InProgress, self::Escalated, self::Resolved],
            self::Assigned => [self::InProgress, self::Escalated, self::Resolved],
            self::InProgress => [self::Escalated, self::Resolved],
            self::Escalated => [self::Assigned, self::InProgress, self::Resolved],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }
}
