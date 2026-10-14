<?php

namespace Database\Seeders;

use App\Domain\Audit\AuditService;
use App\Enums\Role as RoleEnum;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Creates the first System Administrator from ADMIN_EMAIL / ADMIN_PASSWORD. Never hard-codes a password. */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower((string) env('ADMIN_EMAIL'));
        $password = (string) env('ADMIN_PASSWORD');

        if ($email === '' || $password === '') {
            if (app()->environment('production')) {
                throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD before seeding production.');
            }
            $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set: no administrator created.');
            return;
        }
        if (mb_strlen($password) < 10) {
            throw new RuntimeException('ADMIN_PASSWORD must be at least 10 characters.');
        }
        if (User::query()->where('email', $email)->exists()) {
            return;   // never reset an existing account's password from a seeder
        }

        $user = User::query()->create([
            'name' => env('ADMIN_NAME', 'System Administrator'), 'email' => $email, 'password' => $password,
            'status' => UserStatus::Active, 'must_change_password' => app()->environment('production'),
        ]);
        $user->roles()->attach(Role::query()->where('slug', RoleEnum::SystemAdmin->value)->value('id'));
        AuditService::record('admin.seeded', 'users', $user);
    }
}
