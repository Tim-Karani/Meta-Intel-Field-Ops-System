<?php

namespace App\Console\Commands;

use App\Domain\Reports\ScheduledReportService;
use Illuminate\Console\Command;

class SendDueReports extends Command
{
    protected $signature = 'reports:send-due';
    protected $description = 'Email every scheduled report that is due now (runs every 5 minutes; each schedule is sent once a day).';

    public function handle(ScheduledReportService $service): int
    {
        $this->info('Processed '.$service->runDue().' scheduled report(s).');

        return self::SUCCESS;
    }
}
