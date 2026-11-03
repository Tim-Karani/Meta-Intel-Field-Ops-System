<?php

namespace App\Console\Commands;

use App\Enums\Role as RoleEnum;
use App\Models\AgentProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Local only: makes sure the demo sign-ins work (password reset, unlock) and the two demo Team Leaders exist. Safe to run repeatedly. */
class DemoRepair extends Command
{
    protected $signature = 'demo:repair';
    protected $description = 'Reset demo account passwords, unlock them, and add the demo Team Leaders, Executive and HR accounts if missing (local environment only)';

    public function handle(): int
    {
        if (!app()->environment('local', 'testing')) {
            $this->error('Only runs in the local environment.');

            return self::FAILURE;
        }
        DB::transaction(function () {
            $sup = User::query()->where('email', 'demo.supervisor@example.com')->first();
            $make = function (string $email, string $name, RoleEnum $role) {
                $u = User::query()->firstOrCreate(['email' => $email], ['name' => "[DEMO] $name", 'password' => DemoSeeder::DEMO_PASSWORD, 'status' => 'active', 'phone' => '+2547'.random_int(10000000, 99999999)]);
                if (!$u->roles()->where('roles.slug', $role->value)->exists()) {
                    $roleId = Role::query()->where('slug', $role->value)->value('id');
                    if ($roleId) {
                        $u->roles()->attach($roleId);
                    }
                }

                return $u;
            };
            foreach ([['demo.teamleader@example.com', 'Team Leader Nairobi', 'Nairobi', 'DEMO-TL1', ['demo.agent1@example.com', 'demo.agent2@example.com']],
                      ['demo.teamleader2@example.com', 'Team Leader Mombasa', 'Mombasa', 'DEMO-TL2', ['demo.agent3@example.com', 'demo.agent4@example.com']]] as [$email, $name, $region, $code, $agents]) {
                $tl = $make($email, $name, RoleEnum::TeamLeader);
                AgentProfile::query()->updateOrCreate(['user_id' => $tl->id], ['agent_type' => 'team_leader', 'employment_type' => 'permanent', 'agent_code' => $code, 'supervisor_id' => $sup?->id, 'region' => $region]);
                AgentProfile::query()->whereIn('user_id', User::query()->whereIn('email', $agents)->select('id'))->update(['team_leader_id' => $tl->id, 'region' => $region]);
                $this->info("Team Leader ready: $email");
            }
            foreach ([['demo.executive@example.com', 'Executive', RoleEnum::Executive], ['demo.hr@example.com', 'HR Payroll', RoleEnum::Hr]] as [$email, $name, $role]) {
                $make($email, $name, $role);
                $this->info("Account ready: $email");
            }
            AgentProfile::query()->where('pay_rate', 0)->whereIn('user_id', User::query()->where('email', 'like', 'demo.%@example.com')->select('id'))->update(['pay_type' => 'daily', 'pay_rate' => 2000]);
        });
        $n = User::query()->where('email', 'like', 'demo.%@example.com')->update(['password' => bcrypt(DemoSeeder::DEMO_PASSWORD), 'failed_logins' => 0, 'locked_until' => null, 'status' => 'active']);
        $this->info("$n demo accounts reset. Password: ".DemoSeeder::DEMO_PASSWORD);

        return self::SUCCESS;
    }
}
