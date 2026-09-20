<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

use Composer\InstalledVersions;
use RuntimeException;

/**
 * The installed `kinetis/*` packages an Orbitron document reports, read
 * once per instance from the records handed to the constructor.
 *
 * This is the seam the suite constructs directly: passing a list of
 * PackageFact objects exercises the same filtering, ordering and
 * de-duplication production runs on, without touching Composer's
 * process-global installed state. Passing null — what the container's
 * autowiring does when it builds a command — reads that state instead;
 * a command is one short-lived invocation, so the process cache behind
 * it cannot outlive the set it describes. {@see fromProject()} is the
 * third construction, for a server that does outlive it.
 *
 * Nothing is memoized across instances, so a second construction with
 * different records reports the different records.
 */
final readonly class InstalledPackages
{
    private const string PREFIX = 'kinetis/';
    private const string ORBITRON = 'kinetis/orbitron';

    /** Composer's generated inventory, under the project's own vendor directory. */
    private const string INVENTORY = '/vendor/composer/installed.php';

    /** @var array<string, string> package name => pretty version, ordered by name */
    private array $versions;

    /** @var array<string, true> the names above Composer reports as the root project */
    private array $roots;

    /**
     * Where a real installed dependency lives and at what version — the
     * one place an install path is retained for use rather than only as
     * a presence test, and the lookup {@see source()} answers from.
     *
     * @var array<string, array{version: string, root: string}>
     */
    private array $sources;

    /**
     * @param list<PackageFact>|null $facts the records to read, or null to
     *        read Composer's own installed set — the production path.
     */
    public function __construct(?array $facts = null)
    {
        $versions = [];
        $roots = [];
        $paths = [];

        foreach ($facts ?? self::readComposer() as $fact) {
            // A name that is only replaced or provided carries neither a
            // version nor an install path; a metapackage carries a version
            // but never an install path, having no files of its own to put
            // one under. Both are required here: either check alone would
            // let such a name through as a package with a readable source,
            // which neither one has. `??=` keeps the first record for a
            // name, matching Composer's own first-match lookup.
            if (!str_starts_with($fact->name, self::PREFIX)
                || $fact->version === null
                || $fact->installPath === null) {
                continue;
            }

            $versions[$fact->name] ??= $fact->version;
            $paths[$fact->name] ??= $fact->installPath;

            if ($fact->root) {
                $roots[$fact->name] = true;
            }
        }

        ksort($versions, SORT_STRING);

        $sources = [];

        foreach ($versions as $name => $version) {
            // The root project is the checkout being developed, not
            // something this project installed, so it is not a package
            // whose installed source can be read.
            if (!isset($roots[$name])) {
                $sources[$name] = ['version' => $version, 'root' => $paths[$name]];
            }
        }

        $this->versions = $versions;
        $this->roots = $roots;
        $this->sources = $sources;
    }

    /**
     * A fresh snapshot of one project's installed set, taken from the
     * generated inventory under its own vendor directory.
     *
     * `Composer\InstalledVersions` requires that file once and keeps it
     * in `$installed` and `$installedByVendor` for the life of the
     * process, so a server that outlives a Composer dependency change
     * cannot observe the new set through it. Reading the generated file
     * is the same data without that retention, and without mutating
     * Composer's process-global state the way `reload()` would.
     *
     * The shape read here is the one `Composer\InstalledVersions`
     * documents and itself requires: a `root` naming the project, and a
     * `versions` map keyed by package name whose entries carry
     * `pretty_version` only when something is really installed under
     * that name, and `install_path` the same way except that a
     * metapackage — installed, but with no files of its own — carries it
     * as an explicit `null` rather than a string. Those are the fields
     * the constructor above already consumes; nothing else is
     * interpreted, and each one is required to be what Composer writes.
     *
     * @param string $projectRoot the detected consumer root, never a path a caller chose
     * @throws RuntimeException when no generated inventory is there, or it does not carry
     *         that shape — a truthful failure, rather than an older set reported as current.
     */
    public static function fromProject(string $projectRoot): self
    {
        $inventory = $projectRoot . self::INVENTORY;

        // Tested before the include, so a project without one fails with
        // this exception rather than a PHP warning on a stdout that
        // carries JSON-RPC frames.
        if (!is_file($inventory)) {
            throw new RuntimeException("No Composer inventory was found at {$inventory}.");
        }

        // require, not require_once: fromProject() may be called more
        // than once per process for a changed inventory, and
        // require_once would return true instead of the array.
        /** @var mixed $data */
        $data = require $inventory; // NOSONAR

        if (!\is_array($data)
            || !\is_string($data['root']['name'] ?? null)
            || !\is_array($data['versions'] ?? null)) {
            throw new RuntimeException("The Composer inventory at {$inventory} is not the generated shape.");
        }

        $root = $data['root']['name'];
        $facts = [];

        /** @var mixed $entry */
        foreach ($data['versions'] as $name => $entry) {
            // Each entry is required to be what Composer generates, not
            // read as far as it goes: coercing a malformed entry to an
            // empty one would drop a package that is installed and report
            // the remainder as the project's whole set.
            if (!\is_string($name) || !\is_array($entry)) {
                throw new RuntimeException("The Composer inventory at {$inventory} is not the generated shape.");
            }

            $facts[] = new PackageFact(
                $name,
                self::optional($entry, 'pretty_version', $name, $inventory),
                self::optional($entry, 'install_path', $name, $inventory, nullable: true),
                $name === $root,
            );
        }

        return new self($facts);
    }

    /**
     * One of the fields an entry carries only when something is really
     * installed under that name.
     *
     * Absent is the meaning Composer gives a name that is merely
     * replaced or provided, and the constructor drops such a name. A
     * field that is there but is not a string has no such meaning, so it
     * fails here rather than being read as absent — which would report
     * an installed package as one that is not on disk. `install_path`
     * alone carries one further, Composer-documented meaning for
     * present-and-null: a metapackage, which is installed but has no
     * files of its own for a path to name. `$nullable` admits that
     * one value for that one field; `pretty_version` never passes it, so
     * a present null there still fails, matching Composer giving no such
     * allowance to versions.
     *
     * @param array<array-key, mixed> $entry
     * @throws RuntimeException when the field is present, is not a
     *         string, and is not the one admitted null
     */
    private static function optional(
        array $entry,
        string $field,
        string $name,
        string $inventory,
        bool $nullable = false,
    ): ?string {
        if (!\array_key_exists($field, $entry)) {
            return null;
        }

        $value = $entry[$field];

        if ($nullable && $value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw new RuntimeException(
                "The Composer inventory at {$inventory} carries a non-string \"{$field}\" for \"{$name}\".",
            );
        }

        return $value;
    }

    /**
     * The version and install root of one real installed, non-root
     * `kinetis/*` package, or null for every other name — including a
     * name that is only replaced or provided, and the root project
     * itself.
     *
     * This is the only accessor that hands out an install path, and it
     * hands it to the source reader alone. No document built from this
     * object carries one: {@see records()} reports names and versions,
     * and the reader reports neither the root it resolved nor the path
     * it opened.
     *
     * @return array{version: string, root: string}|null
     */
    public function source(string $name): ?array
    {
        return $this->sources[$name] ?? null;
    }

    /**
     * The installed packages as the `{name, version}` records the
     * context and inventory documents carry, in name order.
     *
     * The Composer root project is left out: it is the project being
     * developed, not something this project installed, so reporting it
     * as a dependency at a version would be untrue. It is still retained
     * above, because {@see orbitronVersion()} needs it when Orbitron
     * itself is the root. Install paths never reach these records;
     * {@see source()} is the one place they leave this object.
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
