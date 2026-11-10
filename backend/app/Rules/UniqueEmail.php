<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An email is taken if any account holds it in any capitalisation, including soft-deleted accounts (the database index covers those too). */
class UniqueEmail implements ValidationRule
{
    public function __construct(private ?string $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            return;   // the email rule reports this
        }

        $taken = User::withTrashed()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreUserId, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail('The :attribute has already been taken.');
        }
    }
}
