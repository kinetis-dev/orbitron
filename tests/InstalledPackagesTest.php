<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The retention rules every Orbitron document is built on. Every rule is
 * decided from records constructed here, so none of it depends on what
 * happens to be installed beside the suite; the last cases read a real
 * inventory — Composer's own process set, and a project's generated file
 * — which is what proves the two production readers build those records
 * correctly.
 */
final class InstalledPackagesTest extends TestCase
{
    /** @var list<string> the throwaway project roots {@see project()} made */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            @unlink($root . '/vendor/composer/installed.php');
            @rmdir($root . '/vendor/composer');
            @rmdir($root . '/vendor');
            @rmdir($root);
        }

        $this->roots = [];
    }

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

    /**
     * The two questions this object answers separately, decided from one
     * record set. The reported inventory is the harness's own, so a
     * package outside the `kinetis/` vendor is not a record. Its
     * installed source is still the authority for its own behavior, so
     * the same package keeps a source root: the exact dependency a
     * Kinetis behavior turns on is readable without being reported as
     * something Kinetis ships.
     */
    public function test_a_package_outside_the_kinetis_vendor_keeps_a_source_but_not_a_record(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
            new PackageFact('psr/log', '3.0.2', '/app/vendor/psr/log'),
            new PackageFact('revolt/event-loop', '1.0.9', '/app/vendor/revolt/event-loop'),
            new PackageFact('kinetisx/not-ours', '9.9.9', '/app/vendor/kinetisx/not-ours'),
        ]);

        self::assertSame([['name' => 'kinetis/framework', 'version' => '1.11.2']], $packages->records());
        self::assertSame(['version' => '3.0.2', 'root' => '/app/vendor/psr/log'], $packages->source('psr/log'));
        self::assertSame(
            ['version' => '1.0.9', 'root' => '/app/vendor/revolt/event-loop'],
            $packages->source('revolt/event-loop'),
        );
        self::assertSame(
            ['version' => '9.9.9', 'root' => '/app/vendor/kinetisx/not-ours'],
            $packages->source('kinetisx/not-ours'),
        );
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
     * The one lookup that hands out an install path, and the exact set
     * of names it answers for: a real installed dependency of any
     * vendor, and nothing else — not the root project, whatever vendor
     * that project belongs to, not a name that is only replaced or
     * provided, not a metapackage, and not a name that was never
     * installed.
     *
     * The root case is the one this breadth could lose. A project
     * developed under its own vendor is still the checkout being
     * written, not something it installed, so admitting every vendor
     * must not turn the application itself into a readable package.
     */
    public function test_only_a_real_installed_non_root_package_has_a_readable_source(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('acme/shop', '1.3.0', '/app', root: true),
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
            new PackageFact('kinetis/replaced-by-framework', null, null),
            new PackageFact('thesis/amqp', '0.9.1', '/app/vendor/thesis/amqp'),
            new PackageFact('psr/container-implementation', null, null),
            new PackageFact('symfony/polyfill', '1.31.0', null),
        ]);

        self::assertSame(
            ['version' => '1.11.2', 'root' => '/app/vendor/kinetis/framework'],
            $packages->source('kinetis/framework'),
        );
        self::assertSame(
            ['version' => '0.9.1', 'root' => '/app/vendor/thesis/amqp'],
            $packages->source('thesis/amqp'),
        );

        $excluded = [
            'acme/shop',
            'kinetis/replaced-by-framework',
            'psr/container-implementation',
            'symfony/polyfill',
            'kinetis/absent',
            'thesis/absent',
        ];

        foreach ($excluded as $name) {
            self::assertNull($packages->source($name), $name);
        }

        self::assertSame([['name' => 'kinetis/framework', 'version' => '1.11.2']], $packages->records());
    }

    /**
     * The lookup keeps Composer's own first-match rule, the same one the
     * versions follow, so a name reported twice resolves to one root.
     */
    public function test_the_source_lookup_keeps_the_first_record_for_a_name(): void
    {
        $packages = new InstalledPackages([
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
            new PackageFact('kinetis/framework', '1.0.0', '/other/vendor/kinetis/framework'),
        ]);

        self::assertSame(
            ['version' => '1.11.2', 'root' => '/app/vendor/kinetis/framework'],
            $packages->source('kinetis/framework'),
        );
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
     * The other production path: the project's own generated inventory,
     * read without Composer's process cache in the way.
     *
     * The shape here is the generated one, and every rule the records
     * above establish is decided off it — the root names its own version
     * while staying out of the records and out of the source lookup, a
     * replaced-only entry carries neither field — which the entry shape
     * admits, rather than refusing the read around it — and is dropped, a
     * non-`kinetis/*` package stays out of the records while keeping the
     * install root that makes its source readable, and a real
     * dependency's install root reaches the lookup the source reader
     * answers from.
     */
    public function test_it_reads_a_projects_generated_inventory(): void
    {
        $packages = InstalledPackages::fromProject($this->project([
            'root' => ['name' => 'kinetis/orbitron'],
            'versions' => [
                'kinetis/framework' => [
                    'pretty_version' => '1.12.2',
                    'install_path' => '/app/vendor/kinetis/framework',
                ],
                'kinetis/orbitron' => ['pretty_version' => '1.2.1', 'install_path' => '/app/'],
                'kinetis/replaced' => ['dev_requirement' => false, 'replaced' => ['1.0']],
                'psr/log' => ['pretty_version' => '3.0.2', 'install_path' => '/app/vendor/psr/log'],
            ],
        ]));

        self::assertSame([['name' => 'kinetis/framework', 'version' => '1.12.2']], $packages->records());
        self::assertSame('1.2.1', $packages->orbitronVersion());
        self::assertSame(
            ['version' => '1.12.2', 'root' => '/app/vendor/kinetis/framework'],
            $packages->source('kinetis/framework'),
        );
        self::assertSame(
            ['version' => '3.0.2', 'root' => '/app/vendor/psr/log'],
            $packages->source('psr/log'),
        );
        self::assertNull($packages->source('kinetis/orbitron'));
        self::assertNull($packages->source('kinetis/replaced'));
    }

    /**
     * The concrete defect this reader must not have: a metapackage is
     * installed, at a real version, but installs no files of its own and
     * so has no install path, which Composer reports as an explicit
     * `null` `install_path` rather than a string — the one value
     * `install_path` carries that `pretty_version` never does. A real
     * inventory carries one beside genuine Kinetis entries, and the read
     * must succeed rather than fail the whole snapshot over an entry
     * that is not malformed, only sourceless. Whether the metapackage
     * itself is `kinetis/*` or not, it has no readable source root and
     * so has no record and no source of its own.
     */
    public function test_it_reads_a_generated_inventory_carrying_a_metapackage(): void
    {
        $packages = InstalledPackages::fromProject($this->project([
            'root' => ['name' => 'kinetis/orbitron'],
            'versions' => [
                'kinetis/framework' => [
                    'pretty_version' => '1.12.2',
                    'install_path' => '/app/vendor/kinetis/framework',
                ],
                'kinetis/meta' => ['pretty_version' => '1.0.0', 'install_path' => null],
                'spiral/roadrunner' => ['pretty_version' => 'v2025.1.15', 'install_path' => null],
            ],
        ]));

        self::assertSame([['name' => 'kinetis/framework', 'version' => '1.12.2']], $packages->records());
        self::assertNull($packages->source('kinetis/meta'));
        self::assertNull($packages->source('spiral/roadrunner'));
    }

    /**
     * A server outlives a Composer dependency change and reads the same
     * project root again for every operation, so a second read reports
     * the set that is on disk then, not the one the first read saw.
     *
     * The distinction lives in the include form. Every case above writes
     * its own root, so a `_once` include satisfies them all; read twice
     * from one root it returns `true` rather than the inventory, which
     * this reader refuses as a shape it does not recognize. Changing the
     * file under one root is what separates the two.
     */
    public function test_it_rereads_one_projects_inventory_after_it_changes(): void
    {
        $root = $this->project([
            'root' => ['name' => 'orbitron/consumer'],
            'versions' => [
                'kinetis/framework' => [
                    'pretty_version' => '1.12.2',
                    'install_path' => '/app/vendor/kinetis/framework',
                ],
            ],
        ]);

        self::assertSame(
            [['name' => 'kinetis/framework', 'version' => '1.12.2']],
            InstalledPackages::fromProject($root)->records(),
        );

        self::writeInventory($root, [
            'root' => ['name' => 'orbitron/consumer'],
            'versions' => [
                'kinetis/framework' => [
                    'pretty_version' => '1.13.0',
                    'install_path' => '/app/vendor/kinetis/framework',
                ],
                'kinetis/queue' => [
                    'pretty_version' => '1.3.2',
                    'install_path' => '/app/vendor/kinetis/queue',
                ],
            ],
        ]);

        $reread = InstalledPackages::fromProject($root);

        self::assertSame(
            [
                ['name' => 'kinetis/framework', 'version' => '1.13.0'],
                ['name' => 'kinetis/queue', 'version' => '1.3.2'],
            ],
            $reread->records(),
        );
        self::assertSame(
            ['version' => '1.3.2', 'root' => '/app/vendor/kinetis/queue'],
            $reread->source('kinetis/queue'),
        );
    }

    /**
     * A project with no generated inventory has no installed set to
     * report, and saying so is the only truthful answer: a server that
     * fell back to whatever it read earlier would report a set that is no
     * longer the project's.
     */
    public function test_it_refuses_a_project_with_no_generated_inventory(): void
    {
        $root = $this->project(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($root . '/vendor/composer/installed.php');

        InstalledPackages::fromProject($root);
    }

    /**
     * Neither is a file that is there but is not the generated shape.
     * Reading it as far as it goes would report a partial set as a
     * complete one.
     */
    public function test_it_refuses_a_generated_inventory_that_is_not_the_documented_shape(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not the generated shape');

        InstalledPackages::fromProject($this->project(['versions' => []]));
    }

    /**
     * Every entry of a generated inventory that is there but is not what
     * Composer writes, and the reason each one cannot be read as far as
     * it goes.
     *
     * Each case names a `kinetis/*` package a tolerant read would answer
     * without: coercing a non-array entry to an empty one, or reading a
     * field that is present but is not a string as an absent one, turns a
     * package this project has installed into one the reader reports as
     * absent. That is the `package_unknown` answer this reader exists to
     * stop, arrived at from a different direction, so a partial set here
     * must fail rather than be reported as the project's whole set.
     *
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function malformedEntryProvider(): iterable
    {
        yield 'an entry that is not an array' => [
            ['kinetis/queue' => '1.3.2'],
            'is not the generated shape',
        ];

        yield 'a null pretty_version beside a real install path' => [
            ['kinetis/queue' => ['pretty_version' => null, 'install_path' => '/app/vendor/kinetis/queue']],
            'non-string "pretty_version" for "kinetis/queue"',
        ];

        yield 'a pretty_version that is not a string' => [
            ['kinetis/queue' => ['pretty_version' => 132, 'install_path' => '/app/vendor/kinetis/queue']],
            'non-string "pretty_version" for "kinetis/queue"',
        ];

        yield 'an install_path that is not a string' => [
            ['kinetis/queue' => ['pretty_version' => '1.3.2', 'install_path' => ['/app/vendor/kinetis/queue']]],
            'non-string "install_path" for "kinetis/queue"',
        ];
    }

    /**
     * @param array<array-key, mixed> $versions
     */
    #[DataProvider('malformedEntryProvider')]
    public function test_it_refuses_a_generated_inventory_entry_outside_the_documented_shape(
        array $versions,
        string $reason,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($reason);

        InstalledPackages::fromProject($this->project([
            'root' => ['name' => 'orbitron/consumer'],
            'versions' => $versions,
        ]));
    }

    /**
     * The versions map is keyed by package name. A key that is not one is
     * not the generated file at all, and reading past it would report
     * whatever remains as this project's installed set.
     */
    public function test_it_refuses_a_versions_key_that_is_not_a_package_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not the generated shape');

        InstalledPackages::fromProject($this->project([
            'root' => ['name' => 'orbitron/consumer'],
            'versions' => [7 => ['pretty_version' => '1.3.2', 'install_path' => '/app/vendor/kinetis/queue']],
        ]));
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

    /**
     * A throwaway project root carrying $inventory where Composer writes
     * the generated file, or carrying no inventory at all.
     *
     * @param array<string, mixed>|null $inventory
     */
    private function project(?array $inventory): string
    {
        $root = sys_get_temp_dir() . '/orbitron-inventory-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($root . '/vendor/composer', 0o700, true), "Could not create {$root}.");

        $this->roots[] = $root;

        if ($inventory !== null) {
            self::writeInventory($root, $inventory);
        }

        return $root;
    }

    /**
     * Puts the generated file where Composer writes it, replacing one
     * already there.
     *
     * @param array<string, mixed> $inventory
     */
    private static function writeInventory(string $root, array $inventory): void
    {
        file_put_contents(
            $root . '/vendor/composer/installed.php',
            '<?php return ' . var_export($inventory, true) . ';',
        );
    }
}
