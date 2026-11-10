<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only: lists the accounts migration 000015 left untouched because their emails differ only by capitals. */
class UserEmailConflicts extends Command
{
    protected $signature = 'users:email-conflicts';
    protected $description = 'List accounts whose emails differ only by capitals, for manual resolution (read-only)';

    public function handle(): int
    {
        $rows = DB::table('users')
            ->whereRaw('lower(email) in (select lower(email) from users group by lower(email) having count(*) > 1)')
            ->orderByRaw('lower(email)')->orderBy('created_at')
            ->get(['id', 'email', 'status', 'last_login_at', 'created_at', 'deleted_at']);

        if ($rows->isEmpty()) {
            $this->info('No conflicting email addresses found.');

            return self::SUCCESS;
        }

        $this->warn($rows->count().' accounts share '.$rows->map(fn ($r) => mb_strtolower($r->email))->unique()->count().' address(es). Nothing was changed. Give each person a distinct address, then save each remaining account once so it is stored in lower case.');
        $this->table(['ID', 'Email', 'Status', 'Last login', 'Created', 'Deleted'], $rows->map(fn ($r) => (array) $r)->all());

        return self::SUCCESS;
    }
}
