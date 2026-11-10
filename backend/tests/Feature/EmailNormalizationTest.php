<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

class EmailNormalizationTest extends DatabaseTestCase
{
    private const MIGRATION = '2026_01_01_000015_v19_lowercase_emails.php';

    /** Inserts directly, bypassing the model's lower-casing, to reproduce rows stored before this fix. */
    private function storedUser(string $email, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert($extra + [
            'id' => $id, 'name' => 'Stored', 'email' => $email, 'password' => Hash::make(Scenario::PASSWORD), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function storedEmail(string $id): string
    {
        return DB::table('users')->where('id', $id)->value('email');
    }

    private function failedLogins(string $id): int
    {
        return (int) DB::table('users')->where('id', $id)->value('failed_logins');
    }

    private function login(string $login, string $password = Scenario::PASSWORD)
    {
        return $this->api('POST', 'auth/login', ['login' => $login, 'password' => $password, 'device_name' => 'phpunit']);
    }

    private function runMigration(): object
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->up();

        return $migration;
    }

    private function actingAsAdmin(): User
    {
        $admin = Scenario::user('Admin', Role::SystemAdmin);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function newUserPayload(string $email): array
    {
        return ['name' => 'New Person', 'email' => $email, 'role' => 'analyst', 'password' => Scenario::PASSWORD];
    }

    // Login

    public function test_login_ignores_capitals_typed_for_a_lower_case_account(): void
    {
        $id = $this->storedUser('person@example.test');

        $this->login('PERSON@EXAMPLE.TEST')->assertOk()->assertJsonPath('data.user.id', $id);
        $this->login('Person@Example.Test')->assertOk()->assertJsonPath('data.user.id', $id);
    }

    public function test_an_account_stored_with_capitals_signs_in_however_it_is_typed(): void
    {
        $id = $this->storedUser('Mixed.Case@Example.test');

        foreach (['mixed.case@example.test', 'MIXED.CASE@EXAMPLE.TEST', 'Mixed.Case@Example.test'] as $typed) {
            $this->login($typed)->assertOk()->assertJsonPath('data.user.id', $id);
        }
    }

    public function test_equivalent_accounts_are_refused_when_the_typed_address_matches_neither_exactly(): void
    {
        $first = $this->storedUser('Twin@example.test');
        $second = $this->storedUser('TWIN@example.test');

        $refused = $this->login('twin@example.test')->assertStatus(401);
        $unknown = $this->login('nobody@example.test')->assertStatus(401);

        $this->assertSame($unknown->json('message'), $refused->json('message'));
        $this->assertSame($unknown->json('code'), $refused->json('code'));
        $this->assertSame(0, $this->failedLogins($first));
        $this->assertSame(0, $this->failedLogins($second));

        $reasons = SecurityEvent::query()->where('type', 'login_failed')->get()->pluck('meta.reason')->all();
        $this->assertContains('ambiguous_email', $reasons);
    }

    public function test_the_address_typed_exactly_as_stored_signs_in_only_that_account(): void
    {
        $lower = $this->storedUser('pair@example.test');
        $mixed = $this->storedUser('Pair@example.test');

        $this->login('pair@example.test')->assertOk()->assertJsonPath('data.user.id', $lower);
        $this->login('Pair@example.test')->assertOk()->assertJsonPath('data.user.id', $mixed);
    }

    public function test_a_wrong_password_only_counts_against_the_exactly_matched_account(): void
    {
        $lower = $this->storedUser('pair@example.test');
        $mixed = $this->storedUser('Pair@example.test');

        $this->login('pair@example.test', 'wrong-password')->assertStatus(401);

        $this->assertSame(1, $this->failedLogins($lower));
        $this->assertSame(0, $this->failedLogins($mixed));
    }

    // Creating and editing users

    public function test_creating_a_user_with_an_existing_email_in_other_capitals_is_a_validation_error(): void
    {
        $this->actingAsAdmin();
        $this->storedUser('taken@example.test');
        $before = User::query()->count();

        $this->api('POST', 'users', $this->newUserPayload('TAKEN@Example.Test'))->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame($before, User::query()->count());
    }

    public function test_an_email_held_by_a_soft_deleted_account_is_still_taken(): void
    {
        $this->actingAsAdmin();
        $this->storedUser('gone@example.test', ['deleted_at' => now()]);

        $this->api('POST', 'users', $this->newUserPayload('Gone@example.test'))->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_new_user_typed_with_capitals_is_stored_in_lower_case(): void
    {
        $this->actingAsAdmin();

        $id = $this->api('POST', 'users', $this->newUserPayload('New.Person@Example.Test'))->assertCreated()->json('data.id');

        $this->assertSame('new.person@example.test', $this->storedEmail($id));
    }

    public function test_a_duplicate_that_slips_past_validation_is_reported_without_database_details(): void
    {
        $this->actingAsAdmin();
        // Another request inserting the same address between validation and save.
        User::creating(function (User $user) {
            if ($user->email === 'race@example.test') {
                $this->storedUser('race@example.test');
            }
        });

        $res = $this->api('POST', 'users', $this->newUserPayload('race@example.test'))->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        $this->assertStringNotContainsString('users_email_unique', $res->getContent());
    }

    public function test_editing_a_user_to_another_accounts_email_in_other_capitals_is_a_validation_error(): void
    {
        $this->actingAsAdmin();
        $this->storedUser('owner@example.test');
        $target = $this->storedUser('target@example.test');

        $this->api('PATCH', "users/{$target}", ['email' => 'OWNER@example.test'])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame('target@example.test', $this->storedEmail($target));
    }

    public function test_a_user_can_change_the_capitals_of_their_own_email_and_it_is_stored_in_lower_case(): void
    {
        $this->actingAsAdmin();
        $target = $this->storedUser('Target@example.test');

        $this->api('PATCH', "users/{$target}", ['email' => 'TARGET@example.test'])->assertOk();

        $this->assertSame('target@example.test', $this->storedEmail($target));
    }

    // Migration

    public function test_migration_lower_cases_emails_and_changes_nothing_else(): void
    {
        $mixed = $this->storedUser('Normal.User@Example.test', ['name' => 'Keep Me', 'password' => 'keep-this-hash', 'status' => 'disabled']);
        $lower = $this->storedUser('already@example.test');
        $before = DB::table('users')->where('id', $mixed)->first();

        $this->runMigration();

        $after = DB::table('users')->where('id', $mixed)->first();
        $this->assertSame('normal.user@example.test', $after->email);
        unset($before->email, $after->email);
        $this->assertEquals($before, $after);
        $this->assertSame('already@example.test', $this->storedEmail($lower));
    }

    public function test_migration_leaves_a_mixed_case_account_alone_when_the_lower_case_address_is_taken(): void
    {
        $lower = $this->storedUser('clash@example.test');
        $mixed = $this->storedUser('Clash@example.test');
        $count = DB::table('users')->count();

        $this->runMigration();

        $this->assertSame('clash@example.test', $this->storedEmail($lower));
        $this->assertSame('Clash@example.test', $this->storedEmail($mixed));
        $this->assertSame($count, DB::table('users')->count());
    }

    public function test_migration_does_not_fail_when_only_capitalised_variants_collide(): void
    {
        $first = $this->storedUser('Variant@example.test');
        $second = $this->storedUser('VARIANT@example.test');
        $single = $this->storedUser('Single@example.test');

        $this->runMigration();

        $this->assertSame('Variant@example.test', $this->storedEmail($first));
        $this->assertSame('VARIANT@example.test', $this->storedEmail($second));
        $this->assertSame('single@example.test', $this->storedEmail($single), 'accounts without a conflict are still normalized');
    }

    public function test_migration_treats_soft_deleted_accounts_as_conflicts(): void
    {
        $deleted = $this->storedUser('Ghost@example.test', ['deleted_at' => now()]);
        $active = $this->storedUser('GHOST@example.test');

        $this->runMigration();

        $this->assertSame('Ghost@example.test', $this->storedEmail($deleted));
        $this->assertSame('GHOST@example.test', $this->storedEmail($active));
    }

    public function test_migration_warns_with_counts_only_when_it_skips_accounts(): void
    {
        $this->storedUser('Warn@example.test');
        $this->storedUser('WARN@example.test');
        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, '2 accounts') && str_contains($message, 'users:email-conflicts') && !str_contains($message, 'example.test'));
    }

    public function test_migration_is_silent_when_there_are_no_conflicts(): void
    {
        $this->storedUser('Solo@example.test');
        Log::spy();

        $this->runMigration();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_migration_can_run_twice_and_rollback_changes_no_data(): void
    {
        $id = $this->storedUser('Twice@example.test');

        $migration = $this->runMigration();
        $this->runMigration();
        $this->assertSame('twice@example.test', $this->storedEmail($id));

        $migration->down();
        $this->assertSame('twice@example.test', $this->storedEmail($id));
    }

    public function test_conflict_report_lists_only_conflicting_accounts_and_changes_nothing(): void
    {
        $first = $this->storedUser('Report@example.test');
        $second = $this->storedUser('report@example.test');
        $other = $this->storedUser('Elsewhere@example.test');

        Artisan::call('users:email-conflicts');
        $output = Artisan::output();

        $this->assertStringContainsString($first, $output);
        $this->assertStringContainsString($second, $output);
        $this->assertStringNotContainsString($other, $output);
        $this->assertSame('Report@example.test', $this->storedEmail($first));
        $this->assertSame('Elsewhere@example.test', $this->storedEmail($other));
    }

    public function test_conflict_report_says_so_when_there_are_none(): void
    {
        $this->storedUser('only@example.test');

        Artisan::call('users:email-conflicts');

        $this->assertStringContainsString('No conflicting email addresses found.', Artisan::output());
    }
}
