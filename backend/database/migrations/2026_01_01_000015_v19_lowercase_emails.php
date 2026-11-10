<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emails typed with capitals (for example by an admin) could not sign in. Store them in lower case.
 *
 * Only an account that is the sole holder of its lower-case address is changed. When two or more accounts would end up with the
 * same address (Foo@x and foo@x, or Foo@x and FOO@x), every one of them is left exactly as it is, soft-deleted accounts included.
 * Accounts are never merged or deleted and never given an address another account holds.
 *
 * `php artisan users:email-conflicts` lists the accounts left for manual resolution. It is read-only and can be run at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE users u SET email = lower(u.email) WHERE u.email <> lower(u.email) AND NOT EXISTS (SELECT 1 FROM users o WHERE lower(o.email) = lower(u.email) AND o.id <> u.id)');

        $conflicts = DB::selectOne('SELECT count(*) AS addresses, coalesce(sum(n), 0) AS accounts FROM (SELECT count(*) AS n FROM users GROUP BY lower(email) HAVING count(*) > 1) g');
        if ((int) $conflicts->addresses > 0) {
            $message = "Email normalization skipped {$conflicts->accounts} accounts sharing {$conflicts->addresses} address(es). Run: php artisan users:email-conflicts";
            Log::warning($message);
            if (app()->runningInConsole() && !app()->runningUnitTests()) {
                fwrite(STDERR, "  WARNING: {$message}\n");
            }
        }
    }

    /**
     * Rollback changes no data. The original capitalisation is not kept anywhere, so it cannot be restored from this migration; only a
     * backup taken beforehand can do that. Rolling back removes the migration's record, and running it again is harmless.
     */
    public function down(): void
    {
        Log::warning('Rolling back 000015_v19_lowercase_emails changes no data: original email capitalisation cannot be restored.');
    }
};
