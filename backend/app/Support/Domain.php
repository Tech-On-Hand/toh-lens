<?php

namespace App\Support;

class Domain
{
    private const PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    /**
     * Reduces whatever a teacher pasted (a URL, "www.x.com/path", "*.x.com") to a
     * bare lowercase hostname, or null if it is not a usable domain. IP addresses
     * and single-label names are rejected on purpose: a rule matches a domain and
     * all its subdomains, which has no meaning for either.
     */
    public static function normalize(string $input): ?string
    {
        $value = mb_strtolower(trim($input));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = preg_replace('#[/?\#].*$#', '', $value);
        $value = preg_replace('#^[^@]*@#', '', $value);
        $value = preg_replace('#:\d+$#', '', $value);
        $value = ltrim($value, '*.');
        $value = rtrim($value, '.');

        if ($value === '' || strlen($value) > 253 || ! preg_match(self::PATTERN, $value)) {
            return null;
        }

        return $value;
    }

    /** A domain rule covers the domain itself and every subdomain. */
    public static function matches(string $host, string $domain): bool
    {
        $host = mb_strtolower($host);

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
