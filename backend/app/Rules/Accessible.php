<?php

namespace App\Rules;

use App\Domain\Access\AccessScope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * Validates that a client-supplied ID refers to a row the authenticated user may see: "never trust
 * IDs from the frontend". A hidden row and a missing row give the same message so IDs can't be probed.
 */
class Accessible implements ValidationRule
{
    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    public function __construct(private string $model, private ?Closure $extra = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = Auth::user();
        if (!$user || !is_string($value)) {
            $fail('The selected :attribute is invalid.');
            return;
        }

        $query = AccessScope::scope((new $this->model)->newQuery(), $user)->whereKey($value);
        if ($this->extra) {
            ($this->extra)($query);
        }

        if (!$query->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
