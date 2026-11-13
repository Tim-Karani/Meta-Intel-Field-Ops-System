<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\SecurityEvents;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\UniqueEmail;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends ApiController
{
    public static function passwordRule(): PasswordRule
    {
        return PasswordRule::min(10)->letters()->mixedCase()->numbers();
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $login = trim($data['login']);
        $matches = User::query()->where(fn ($q) => $q->whereRaw('lower(email) = ?', [mb_strtolower($login)])->orWhere('username', $login))->get();
        $user = self::loginAccount($matches, $login);

        if (!$user) {
            Hash::check($data['password'], self::dummyHash());   // equalise timing so unknown users are not detectable
            SecurityEvents::log('login_failed', null, 'warning', $matches->isEmpty() ? ['reason' => 'unknown_user'] : ['reason' => 'ambiguous_email', 'match_count' => $matches->count()]);
            return ApiResponse::error('Invalid credentials.', 401, [], 'invalid_credentials');
        }

        if ($user->isLocked()) {
            SecurityEvents::log('login_blocked_locked', $user->getKey(), 'warning');
            return ApiResponse::error('This account is temporarily locked. Try again later.', 423, [], 'account_locked');
        }

        if (!Hash::check($data['password'], $user->password)) {
            $fails = $user->failed_logins + 1;
            if ($fails >= (int) config('field.auth.max_failed_logins')) {
                $user->forceFill(['failed_logins' => 0, 'locked_until' => now()->addMinutes((int) config('field.auth.lockout_minutes'))])->saveQuietly();
                SecurityEvents::log('account_locked', $user->getKey(), 'critical', ['after_failures' => $fails]);
            } else {
                $user->forceFill(['failed_logins' => $fails])->saveQuietly();
            }
            SecurityEvents::log('login_failed', $user->getKey(), 'warning', ['reason' => 'bad_password']);
            return ApiResponse::error('Invalid credentials.', 401, [], 'invalid_credentials');
        }

        if (!$user->isActive()) {
            SecurityEvents::log('login_blocked_inactive', $user->getKey(), 'warning', ['status' => $user->status->value]);
            return ApiResponse::error('This account is not active.', 403, [], 'account_inactive');
        }

        $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        Auth::setUser($user);
        AuditService::record('login', 'auth', $user);
        SecurityEvents::log('login_success', $user->getKey());
        try { \App\Domain\Access\DeviceRegistry::touch($user, $request); } catch (\Throwable $e) { report($e); }   // never block a sign-in

        return $this->ok($this->tokenPayload($user, $data['device_name'] ?? 'web'), 'Signed in.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(UserResource::session($request->user()));
    }

    /** Rotate the bearer token: the old one is revoked, a fresh one is issued. */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $name = $user->currentAccessToken()->name;
        $user->currentAccessToken()->delete();

        return $this->ok($this->tokenPayload($user, $name), 'Token refreshed.');
    }

    public function logout(Request $request): JsonResponse
    {
        AuditService::record('logout', 'auth', $request->user());
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Signed out.');
    }

    /**
     * Self-service profile: name, email, username and phone only. Role, client, status and permissions are never changed here.
     * Changing the sign-in email needs the current password, so a borrowed open session cannot take the account over.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', new UniqueEmail($user->getKey())],
            'username' => ['nullable', 'string', 'max:60', 'alpha_dash', \Illuminate\Validation\Rule::unique('users', 'username')->ignore($user->getKey())],
            'phone' => ['nullable', 'string', 'max:40'],
            'current_password' => ['nullable', 'string'],
        ]);

        $newEmail = mb_strtolower(trim($data['email']));
        $emailChanged = $newEmail !== mb_strtolower((string) $user->email);
        if ($emailChanged && !Hash::check((string) ($data['current_password'] ?? ''), $user->password)) {
            return ApiResponse::error('Enter your current password to change your email.', 422, ['current_password' => ['Enter your current password to change your email.']], 'validation_failed');
        }

        $before = $user->only(['name', 'email', 'username', 'phone']);
        $user->forceFill([
            'name' => trim($data['name']), 'email' => $newEmail,
            'username' => ($data['username'] ?? '') === '' ? null : $data['username'],
            'phone' => ($data['phone'] ?? '') === '' ? null : trim($data['phone']),
        ])->save();
        AuditService::record('profile.updated', 'auth', $user, $before, $user->only(['name', 'email', 'username', 'phone']));
        if ($emailChanged) {
            SecurityEvents::log('email_changed', $user->getKey());
        }

        return $this->ok(UserResource::session($user->refresh()), 'Profile saved.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', self::passwordRule(), 'different:current_password'],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return ApiResponse::error('The current password is incorrect.', 422, ['current_password' => ['The current password is incorrect.']], 'validation_failed');
        }

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();
        SecurityEvents::log('password_changed', $user->getKey());

        return $this->ok(null, 'Password changed.');
    }

    /** Always answers 200 so registered e-mail addresses cannot be enumerated. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => strtolower($data['email'])]);
        SecurityEvents::log('password_reset_requested');

        return $this->ok(null, 'If that address is registered, a reset link has been sent.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', self::passwordRule()],
        ]);

        $status = Password::reset(
            ['email' => strtolower($data['email']), 'password' => $data['password'], 'password_confirmation' => $data['password_confirmation'] ?? $data['password'], 'token' => $data['token']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'must_change_password' => false, 'failed_logins' => 0, 'locked_until' => null])->save();
                $user->tokens()->delete();   // sign out everywhere
                SecurityEvents::log('password_reset_completed', $user->getKey(), 'warning');
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('This password reset link is invalid or has expired.', 422, [], 'invalid_reset_token');
        }

        return $this->ok(null, 'Password has been reset. You can sign in now.');
    }

    /**
     * The one account a login refers to, or null when there is none. Accounts whose emails differ only by capitals are never
     * chosen between arbitrarily: only the address typed exactly as stored picks one of them, and nothing else is attempted,
     * so a failed guess cannot add to the lockout count of an account the person may not own.
     *
     * @param Collection<int, User> $matches
     */
    private static function loginAccount(Collection $matches, string $login): ?User
    {
        if ($matches->count() <= 1) {
            return $matches->first();
        }
        $exact = $matches->filter(fn (User $u) => $u->email === $login || $u->username === $login);

        return $exact->count() === 1 ? $exact->first() : null;
    }

    private function tokenPayload(User $user, string $device): array
    {
        $expires = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $user->createToken(Str::limit($device, 100, ''), ['*'], $expires);

        return ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601String(), 'user' => UserResource::session($user)];
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= Hash::make(Str::random(24));
    }
}
