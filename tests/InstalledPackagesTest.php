<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The retention rules every Orbitron document is built on. Every rule is
 * decided from records constructed here, so none of it depends on what
 * happens to be installed beside the suite; the last case reads
 * Composer's own set, which is what proves the production reader builds
 * those records correctly.
 */
final class InstalledPackagesTest extends TestCase
{
    public function test_it_keeps_kinetis_packages_in_name_order(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/queue', '1.3.2', '/app/vendor/kinetis/queue'),
            new PackageFact('kinetis/orbitron', '1.0.0', '/app/vendor/kinetis/orbitron'),
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
        ]);

        self::assertSame(
            [
                ['name' => 'kinetis/framework', 'version' => '1.11.2'],
                ['name' => 'kinetis/orbitron', 'version' => '1.0.0'],
                ['name' => 'kinetis/queue', 'version' => '1.3.2'],
            ],
            $packages->records(),
        );
    }

    public function test_it_drops_every_package_outside_the_kinetis_vendor(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
            new PackageFact('psr/log', '3.0.2', '/app/vendor/psr/log'),
            new PackageFact('revolt/event-loop', '1.0.9', '/app/vendor/revolt/event-loop'),
            new PackageFact('kinetisx/not-ours', '9.9.9', '/app/vendor/kinetisx/not-ours'),
        ]);

        self::assertSame([['name' => 'kinetis/framework', 'version' => '1.11.2']], $packages->records());
    }

    /**
     * The discriminating case. `getInstalledPackages()` also lists every
     * name an installed package replaces or provides, and Composer
     * reports null for the version and the install path of those: an
     * implementation that reads the name list alone reports packages
     * that are not installed at all.
     */
    public function test_it_drops_a_kinetis_name_that_is_only_replaced_or_provided(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
            new PackageFact('kinetis/replaced-by-framework', null, null),
        ]);

        self::assertSame([['name' => 'kinetis/framework', 'version' => '1.11.2']], $packages->records());
    }

    public function test_it_drops_a_record_missing_either_the_version_or_the_install_path(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/no-version', null, '/app/vendor/kinetis/no-version'),
            new PackageFact('kinetis/no-path', '1.0.0', null),
        ]);

        self::assertSame([], $packages->records());
    }

    /**
     * The other name Composer lists that is not a dependency: the root
     * project itself. A skeleton application reporting `kinetis/skeleton`
     * among its installed packages, at a version, says the project
     * installed itself. Every other record keeps its place and its order.
     */
    public function test_it_omits_the_composer_root_project_from_the_records(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/skeleton', '1.3.0', '/app', root: true),
            new PackageFact('kinetis/orbitron', '1.1.0', '/app/vendor/kinetis/orbitron'),
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
        ]);

        self::assertSame(
            [
                ['name' => 'kinetis/framework', 'version' => '1.11.2'],
                ['name' => 'kinetis/orbitron', 'version' => '1.1.0'],
            ],
            $packages->records(),
        );
    }

    /**
     * Orbitron is the root package while this package is being developed,
     * and the version it reports is its own. Omitting the root from the
     * records must not take that version with it — the two are separate
     * questions asked of the same record.
     */
    public function test_a_root_orbitron_names_its_own_version_while_staying_out_of_the_records(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/orbitron', 'dev-main', '/app/packages/orbitron', root: true),
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
        ]);

        self::assertSame('dev-main', $packages->orbitronVersion());
        self::assertSame([['name' => 'kinetis/framework', 'version' => '1.11.2']], $packages->records());
    }

    public function test_it_reports_one_entry_per_name_and_keeps_the_first_record(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
            new PackageFact('kinetis/framework', '1.0.0', '/other/vendor/kinetis/framework'),
        ]);

        self::assertSame([['name' => 'kinetis/framework', 'version' => '1.11.2']], $packages->records());
    }

    public function test_no_install_path_reaches_the_reported_records(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/framework', '1.11.2', '/home/someone/secret-project/vendor/kinetis/framework'),
        ]);

        self::assertSame([['name' => 'kinetis/framework', 'version' => '1.11.2']], $packages->records());
        self::assertStringNotContainsString(
            'secret-project',
            json_encode($packages->records(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_it_reads_orbitrons_own_version_from_the_detected_record(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/orbitron', 'dev-main', '/app/packages/orbitron'),
        ]);

        self::assertSame('dev-main', $packages->orbitronVersion());
    }

    public function test_it_refuses_to_invent_a_version_when_no_orbitron_record_exists(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kinetis/orbitron');

        $packages->orbitronVersion();
    }

    /**
     * Each instance reports the records it was given: nothing is held in
     * a static between constructions.
     */
    public function test_two_instances_report_their_own_records(): void
    {
        $first = new InstalledPackages([new PackageFact('kinetis/orbitron', '1.0.0', '/app')]);
        $second = new InstalledPackages([new PackageFact('kinetis/orbitron', '1.0.1', '/app')]);

        self::assertSame('1.0.0', $first->orbitronVersion());
        self::assertSame('1.0.1', $second->orbitronVersion());
    }

    /**
     * The production path, against the installed set this suite runs
     * inside: Orbitron is the Composer root here, so this reads the root
     * rule off Composer's own metadata rather than off constructed
     * records — the version is found and the root is not reported as a
     * dependency, while the packages it really installed are.
     */
    public function test_reading_composers_installed_set_finds_the_installed_kinetis_packages(): void
    {
        $packages = new InstalledPackages();
        $names = array_column($packages->records(), 'name');

        self::assertNotSame('', $packages->orbitronVersion());
        self::assertNotContains('kinetis/orbitron', $names);
        self::assertContains('kinetis/framework', $names);
        self::assertContains('kinetis/mcp-docs', $names);
    }
}
