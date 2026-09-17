<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * One record from Composer's installed set: the package name, its pretty
 * version, and the directory it was installed into.
 *
 * Both the version and the install path are nullable because
 * `Composer\InstalledVersions::getInstalledPackages()` also lists every
 * name an installed package *replaces* or *provides*, and reports null
 * for both on those — nothing is on disk under such a name. That
 * distinction is the whole reason this carries three fields instead of
 * two: InstalledPackages reads it to tell a real installation from a
 * claimed one.
 */
final readonly class PackageFact
{
    public function __construct(
        public string $name,
        public ?string $version,
        public ?string $installPath,
    ) {}
}
