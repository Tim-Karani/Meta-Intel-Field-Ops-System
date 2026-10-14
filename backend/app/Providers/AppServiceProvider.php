<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers());

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('login')).'|'.$r->ip()),
            Limit::perMinute(30)->by($r->ip()),
        ]);
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('exports', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
    }
}
