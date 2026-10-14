<?php

namespace App\Console\Commands;

use App\Domain\Assignments\AssignmentService;
use App\Domain\Audit\AuditService;
use Illuminate\Console\Command;

class MarkOverdueAssignments extends Command
{
    protected $signature = 'assignments:mark-overdue';
    protected $description = 'Mark pending/accepted assignments whose date has passed as overdue and notify agent and supervisor.';

    public function handle(AssignmentService $service): int
    {
        $n = $service->markOverdue();
        AuditService::record('assignment.overdue_sweep', 'assignments', null, null, ['marked' => $n]);
        $this->info("Marked {$n} assignment(s) overdue.");
        return self::SUCCESS;
    }
}
