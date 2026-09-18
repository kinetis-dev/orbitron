<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * One record from Composer's installed set: the package name, its pretty
 * version, the directory it was installed into, and whether it is the
 * Composer root project rather than something the project installed.
 *
 * Both the version and the install path are nullable because
 * `Composer\InstalledVersions::getInstalledPackages()` also lists every
 * name an installed package *replaces* or *provides*, and reports null
 * for both on those — nothing is on disk under such a name.
 * InstalledPackages reads that distinction to tell a real installation
 * from a claimed one.
 *
 * The root flag is the other distinction Composer draws and the name
 * list does not: `getInstalledPackages()` includes the root project
 * itself, and `getRootPackage()` is the one place its name is stated.
 * InstalledPackages reads it to keep the project being developed out of
 * the dependencies it reports.
 */
final readonly class PackageFact
{
    public function __construct(
        public string $name,
        public ?string $version,
        public ?string $installPath,
        public bool $root = false,
    ) {}
}
