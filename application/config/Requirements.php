<?php

namespace App\Config;

final class Requirements
{
    public const PHP_MINIMUM_VERSIONS = [
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    public static function supportsPhpVersion(string $version): bool
    {
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        $minimum = self::PHP_MINIMUM_VERSIONS[$branch] ?? null;

        return $minimum !== null && version_compare($version, $minimum, '>=');
    }

    public static function phpVersionLabel(): string
    {
        return implode(' / ', array_map(static fn (string $version): string => $version . '+', self::PHP_MINIMUM_VERSIONS));
    }
}
