<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

use Composer\InstalledVersions;
use RuntimeException;

/**
 * The installed `kinetis/*` packages the Orbitron commands report, read
 * once per instance from the records handed to the constructor.
 *
 * This is the seam the suite constructs directly: passing a list of
 * PackageFact objects exercises the same filtering, ordering and
 * de-duplication production runs on, without touching Composer's
 * process-global installed state. Passing null — what the container's
 * autowiring does when it builds a command — reads that state instead.
 * Nothing is memoized across instances, so a second construction with
 * different records reports the different records.
 */
final readonly class InstalledPackages
{
    private const string PREFIX = 'kinetis/';
    private const string ORBITRON = 'kinetis/orbitron';

    /** @var array<string, string> package name => pretty version, ordered by name */
    private array $versions;

    /** @var array<string, true> the names above Composer reports as the root project */
    private array $roots;

    /**
     * @param list<PackageFact>|null $facts the records to read, or null to
     *        read Composer's own installed set — the production path.
     */
    public function __construct(?array $facts = null)
    {
        $versions = [];
        $roots = [];

        foreach ($facts ?? self::readComposer() as $fact) {
            // A name that is only replaced or provided carries neither a
            // version nor an install path. Both are required here: either
            // one alone would let such a name through as a package that
            // is not installed at all. `??=` keeps the first record for a
            // name, matching Composer's own first-match lookup.
            if (!str_starts_with($fact->name, self::PREFIX)
                || $fact->version === null
                || $fact->installPath === null) {
                continue;
            }

            $versions[$fact->name] ??= $fact->version;

            if ($fact->root) {
                $roots[$fact->name] = true;
            }
        }

        ksort($versions, SORT_STRING);

        $this->versions = $versions;
        $this->roots = $roots;
    }

    /**
     * The installed packages as the `{name, version}` records the
     * context and inventory documents carry, in name order.
     *
     * The Composer root project is left out: it is the project being
     * developed, not something this project installed, so reporting it
     * as a dependency at a version would be untrue. It is still retained
     * above, because {@see orbitronVersion()} needs it when Orbitron
     * itself is the root. Install paths are read as a retention test in
     * the constructor and never leave this object.
     *
     * @return list<array{name: string, version: string}>
     */
    public function records(): array
    {
        $records = [];

        foreach ($this->versions as $name => $version) {
            if (isset($this->roots[$name])) {
                continue;
            }

            $records[] = ['name' => $name, 'version' => $version];
        }

        return $records;
    }

    /**
     * Orbitron's own version, which is the single authority every
     * document reports — the detected package fact, never a constant
     * maintained beside it. It answers from the retained set, so a
     * checkout of this package developing itself still names its
     * version.
     *
     * @throws RuntimeException when the records carry no entry for this
     *         package, the one state in which Orbitron cannot name its
     *         own version.
     */
    public function orbitronVersion(): string
    {
        return $this->versions[self::ORBITRON] ?? throw new RuntimeException(
            'No installed ' . self::ORBITRON . ' package was found, so Orbitron cannot state its own version.',
        );
    }

    /**
     * Composer lists the root project among the installed packages and
     * names it in one other place; both are read here so the records
     * downstream can tell the two apart.
     *
     * @return list<PackageFact>
     */
    private static function readComposer(): array
    {
        $root = InstalledVersions::getRootPackage()['name'];
        $facts = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $facts[] = new PackageFact(
                $name,
                InstalledVersions::getPrettyVersion($name),
                InstalledVersions::getInstallPath($name),
                $name === $root,
            );
        }

        return $facts;
    }
}
