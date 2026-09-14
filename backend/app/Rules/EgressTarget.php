<?php

namespace App\Rules;

use App\Support\IpRange;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An authorised scan target: an IPv4 address or CIDR block that does not
 * overlap the platform's permanently blocked destinations.
 */
class EgressTarget implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || IpRange::parse($value) === null) {
            $fail('The :attribute must be an IPv4 address or CIDR block.');

            return;
        }

        if (! IpRange::isRoutableTarget($value)) {
            $fail('The :attribute overlaps a range the platform never allows, such as a private or link-local network.');
        }
    }
}
