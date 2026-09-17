<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The retention rules every Orbitron document is built on. Each case
 * constructs the records directly, so none of this depends on what
 * happens to be installed beside the suite.
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
     * The production path: reading Composer's own installed set finds
     * this very package, under the same retention rules.
     */
    public function test_reading_composers_installed_set_finds_the_installed_kinetis_packages(): void
    {
        $packages = new InstalledPackages();

        self::assertNotSame('', $packages->orbitronVersion());
        self::assertContains('kinetis/framework', array_column($packages->records(), 'name'));
    }
}
