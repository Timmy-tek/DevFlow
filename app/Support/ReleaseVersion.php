<?php

namespace App\Support;

final class ReleaseVersion
{
    /** v1.2.3 or 1.2.3, optionally with a suffix such as -beta.1 */
    public const PATTERN = '/^v?\d+\.\d+\.\d+(?:[-.][0-9A-Za-z.]+)?$/';

    public static function isValid(string $version): bool
    {
        return preg_match(self::PATTERN, $version) === 1;
    }

    /** @param  array<int, string>  $versions */
    public static function latest(array $versions): ?string
    {
        $valid = array_values(array_filter($versions, fn($v) => self::isValid($v)));

        if ($valid === []) {
            return null;
        }

        usort($valid, fn($a, $b) => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $valid[0];
    }

    /** Suggests the next patch version, or v0.1.0 for the very first release. */
    public static function next(?string $latest): string
    {
        if ($latest !== null && preg_match('/^v?(\d+)\.(\d+)\.(\d+)/', $latest, $m) === 1) {
            return 'v' . $m[1] . '.' . $m[2] . '.' . ((int) $m[3] + 1);
        }

        return 'v0.1.0';
    }
}