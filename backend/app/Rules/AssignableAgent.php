<?php

namespace App\Rules;

use App\Domain\Access\AccessScope;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

class AssignableAgent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = Auth::user();
        if (!$user || !is_string($value) || !AccessScope::assignableAgents(User::query(), $user)->whereKey($value)->exists()) {
            $fail('The selected :attribute is not an assignable field agent.');
        }
    }
}
