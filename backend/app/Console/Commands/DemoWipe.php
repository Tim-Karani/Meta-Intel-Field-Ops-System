<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOCAL DEVELOPMENT ONLY. Removes every record except one named super administrator and the system set-up
 * (roles, permissions, settings, system project templates, outlet types and statuses, GPS rules).
 *
 *   php artisan demo:wipe --email=you@example.com --yes [--audit]
 *
 * It refuses to run in production, and the e-mail must belong to an existing System Administrator.
 * The audit trail is append-only by design, so it is kept unless you add --audit.
 */
class DemoWipe extends Command
{
    protected $signature = 'demo:wipe {--email= : the super administrator to keep} {--yes : confirm you want everything else removed} {--audit : also clear the audit trail and security events}';
    protected $description = 'Remove all data except the named super administrator (local environments only).';

    /** Tables that hold set-up, not business data. They are never emptied here. */
    private const KEEP = [
        'migrations', 'roles', 'permissions', 'role_permissions', 'agency_settings', 'gps_rules',
        'project_templates', 'outlet_types', 'outlet_subtypes', 'outlet_statuses',
        'users', 'user_roles', 'personal_access_tokens', 'audit_logs', 'security_events',
    ];

    public function handle(): int
    {
        if (!app()->environment('local', 'testing')) {
            $this->error('demo:wipe only runs in local or testing environments. Nothing was changed.');
            return self::FAILURE;
        }
        $email = strtolower(trim((string) $this->option('email')));
        if ($email === '') {
            $this->error('Give the super administrator to keep, e.g. --email=you@example.com');
            return self::FAILURE;
        }
        $admin = DB::table('users')->whereRaw('lower(email) = ?', [$email])->first();
        $isAdmin = $admin && DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $admin->id)->where('roles.slug', 'system_admin')->exists();
        if (!$isAdmin) {
            $this->error("No System Administrator with the e-mail {$email}. Nothing was changed.");
            return self::FAILURE;
        }
        if (!$this->option('yes')) {
            $this->warn("This removes ALL data except {$email} and the system set-up. Run again with --yes to go ahead.");
            return self::FAILURE;
        }

        $tables = collect(Schema::getTables())->pluck('name')->map(fn ($n) => str_contains($n, '.') ? substr($n, strrpos($n, '.') + 1) : $n)->unique()->values();
        $wipe = $tables->reject(fn ($t) => in_array($t, self::KEEP, true))->values();
        if ($this->option('audit') && DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE audit_logs, security_events');   // TRUNCATE is not blocked by the append-only row trigger
        } elseif ($this->option('audit')) {
            $wipe = $wipe->merge(['audit_logs', 'security_events'])->values();
        }

        // Tied to the other users, so remove these first.
        DB::table('personal_access_tokens')->where('tokenable_id', '!=', $admin->id)->delete();
        DB::table('user_roles')->where('user_id', '!=', $admin->id)->delete();
        DB::table('users')->where('id', $admin->id)->update(['client_id' => null]);
        DB::table('project_templates')->where('is_system', false)->delete();

        // Foreign keys decide the order, so keep deleting what can be deleted until nothing is left or no progress is made.
        $pending = $wipe->all();
        $pending[] = '__users__';
        for ($pass = 1; $pass <= 12 && $pending; $pass++) {
            $stuck = [];
            foreach ($pending as $table) {
                try {
                    $table === '__users__' ? DB::table('users')->where('id', '!=', $admin->id)->delete() : DB::table($table)->delete();
                } catch (\Throwable $e) {
                    $stuck[] = $table;
                }
            }
            if (count($stuck) === count($pending)) {
                break;
            }
            $pending = $stuck;
        }
        if ($pending) {
            $this->error('Could not empty: '.implode(', ', $pending).'. Nothing else was left half-done, but run the command again or tell me this list.');
            return self::FAILURE;
        }

        $this->info("Done. Kept {$email} and the system set-up; everything else is removed.");
        $this->line('Photos already uploaded to storage (such as Cloudflare R2) are not touched.');

        return self::SUCCESS;
    }
}
