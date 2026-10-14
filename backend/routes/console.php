<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()->toIso8601String(), 600))->everyMinute()->name('heartbeat');
Schedule::command('assignments:mark-overdue')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('auth:clear-resets')->daily();
