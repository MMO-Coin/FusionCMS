<?php

require_once __DIR__ . '/../application/config/Requirements.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Requirements;
use Composer\Semver\Semver;

$manifest = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
$constraint = $manifest['require']['php'];
$cases = [
    '8.2.35' => false,
    '8.3.0' => false,
    '8.3.35' => false,
    '8.3.36RC1' => false,
    '8.3.36' => true,
    '8.3.37' => true,
    '8.4.0' => false,
    '8.4.26' => false,
    '8.4.27' => true,
    '8.4.28' => true,
    '8.5.0' => false,
    '8.5.11' => false,
    '8.5.12' => true,
    '8.5.13' => true,
    '8.6.0' => false,
];

foreach ($cases as $version => $expected) {
    if (Requirements::supportsPhpVersion($version) !== $expected) {
        throw new RuntimeException("Runtime PHP requirement failed for $version");
    }

    if (Semver::satisfies($version, $constraint) !== $expected) {
        throw new RuntimeException("Composer PHP requirement failed for $version");
    }

    echo "PASS: PHP $version is " . ($expected ? 'accepted' : 'rejected') . "\n";
}
