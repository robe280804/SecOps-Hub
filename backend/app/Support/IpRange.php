<?php

namespace App\Support;

/**
 * Minimal IPv4 range arithmetic for egress rules. Only IPv4 is supported:
 * environment networks are created without IPv6, so an IPv6 allowlist entry
 * could never be enforced and must be rejected rather than silently ignored.
 */
class IpRange
{
    /** @return array{0: int, 1: int}|null Inclusive start and end as unsigned integers. */
    public static function parse(string $value): ?array
    {
        $value = trim($value);
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        if ($prefix === null) {
            $prefix = 32;
        } elseif (preg_match('/\A(?:0|[1-9]\d?)\z/', $prefix) !== 1 || (int) $prefix > 32) {
            return null;
        }

        $prefix = (int) $prefix;
        $size = 2 ** (32 - $prefix);
        $start = ip2long($address) & (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;

        return [$start, $start + $size - 1];
    }

    /** Whether two ranges share at least one address. */
    public static function overlaps(string $first, string $second): bool
    {
        $left = self::parse($first);
        $right = self::parse($second);

        if ($left === null || $right === null) {
            return false;
        }

        return $left[0] <= $right[1] && $right[0] <= $left[1];
    }

    /** Whether the address or range sits entirely outside every blocked range. */
    public static function isRoutableTarget(string $value, ?array $blocked = null): bool
    {
        if (self::parse($value) === null) {
            return false;
        }

        foreach ($blocked ?? config('environments.egress.blocked_destinations') as $range) {
            if (self::overlaps($value, $range)) {
                return false;
            }
        }

        return true;
    }
}
