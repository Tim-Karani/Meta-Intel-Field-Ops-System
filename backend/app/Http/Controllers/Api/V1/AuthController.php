<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\SecurityEvents;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
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
        $user = User::query()->where(fn ($q) => $q->where('email', strtolower($login))->orWhere('username', $login))->first();

        if (!$user) {
            Hash::check($data['password'], self::dummyHash());   // equalise timing so unknown users are not detectable
            SecurityEvents::log('login_failed', null, 'warning', ['reason' => 'unknown_user']);
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
