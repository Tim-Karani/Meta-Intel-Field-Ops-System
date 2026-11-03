<?php

namespace App\Console\Commands;

use App\Domain\Assignments\JourneyPlanService;
use Illuminate\Console\Command;

class GenerateJourneyPlanVisits extends Command
{
    protected $signature = 'journey-plans:generate';
    protected $description = 'Create the next two weeks of visits for every active fixed journey plan (safe to run repeatedly).';

    public function handle(JourneyPlanService $service): int
    {
        $this->info('Created '.$service->generateAll().' visit(s) from journey plans.');

        return self::SUCCESS;
    }
}
