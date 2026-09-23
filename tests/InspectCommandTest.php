<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use JsonException;
use Kinetis\Console\CommandArguments;
use Kinetis\Orbitron\Console\InspectCommand;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InspectCommandTest extends TestCase
{
    private StreamCapture $output;
    private StreamCapture $errorOutput;

    /** @var list<string> fixture roots and links, removed in reverse order */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->output = new StreamCapture();
        $this->errorOutput = new StreamCapture();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            is_link($path) ? unlink($path) : rmdir($path);
        }
    }

    /**
     * @param list<PackageFact>|null $facts
     * @param list<string> $argv
     */
    private function invoke(array $argv = [], ?array $facts = null, ?string $root = null): int
    {
        $command = new InspectCommand(
            new Documents(new InstalledPackages($facts ?? [
                new PackageFact('kinetis/queue', '1.3.2', '/app/vendor/kinetis/queue'),
                new PackageFact('kinetis/orbitron', '1.0.0', '/app/vendor/kinetis/orbitron'),
                new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
                new PackageFact('kinetis/replaced-by-framework', null, null),
                new PackageFact('psr/log', '3.0.2', '/app/vendor/psr/log'),
            ])),
            $root ?? $this->directory(),
            $this->output->stream,
            $this->errorOutput->stream,
        );

        return $command->run(CommandArguments::parse($argv));
    }

    private function directory(): string
    {
        $path = sys_get_temp_dir() . '/orbitron-inspect-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path, 0o700));
        $this->paths[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function document(): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->output->contents(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    public function test_it_writes_exactly_one_json_document_with_a_fixed_key_and_list_order(): void
    {
        $root = $this->directory();
        $physical = realpath($root);
        self::assertIsString($physical);
        $encoded = json_encode($physical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertSame(0, $this->invoke(root: $root));

        self::assertSame(
            <<<JSON
            {
                "schemaVersion": 3,
                "orbitronVersion": "1.0.0",
                "projectRoot": {$encoded},
                "checkoutRoot": {$encoded},
                "packages": [
                    {
                        "name": "kinetis/framework",
                        "version": "1.11.2"
                    },
                    {
                        "name": "kinetis/orbitron",
                        "version": "1.0.0"
                    },
                    {
                        "name": "kinetis/queue",
                        "version": "1.3.2"
                    }
                ]
            }

            JSON,
            $this->output->contents(),
        );
        self::assertSame('', $this->errorOutput->contents());
    }

    /**
     * The detected root is lexical; the document reports the checkout it
     * physically names, so a root reached through a symlink segment is
     * reported as its target — as the checkout identity too, since the
     * command has no launcher to hand one over.
     *
     * @throws JsonException
     */
    public function test_a_root_through_a_symlink_is_reported_as_its_physical_path(): void
    {
        $target = $this->directory();
        $link = $target . '-link';
        self::assertTrue(symlink($target, $link));
        $this->paths[] = $link;

        self::assertSame(0, $this->invoke(root: $link . '/.'));

        self::assertSame(realpath($target), $this->document()['projectRoot']);
        self::assertSame(realpath($target), $this->document()['checkoutRoot']);
        self::assertNotSame($link, $this->document()['projectRoot']);
    }

    /**
     * Two checkouts with the same installed set are distinguished by
     * the root alone.
     *
     * @throws JsonException
     */
    public function test_two_physical_roots_with_the_same_inventory_report_different_roots(): void
    {
        $first = $this->directory();
        self::assertSame(0, $this->invoke(root: $first));
        $firstDocument = $this->document();

        $this->setUp();

        $second = $this->directory();
        self::assertSame(0, $this->invoke(root: $second));
        $secondDocument = $this->document();

        self::assertSame($firstDocument['packages'], $secondDocument['packages']);
        self::assertSame(realpath($first), $firstDocument['projectRoot']);
        self::assertSame(realpath($second), $secondDocument['projectRoot']);
        self::assertNotSame($firstDocument['projectRoot'], $secondDocument['projectRoot']);
    }

    /**
     * A root that resolves to nothing fails rather than being reported
     * lexically, and no document reaches STDOUT.
     */
    public function test_an_unresolvable_root_fails_without_a_document(): void
    {
        $missing = sys_get_temp_dir() . '/orbitron-inspect-missing-' . bin2hex(random_bytes(8));

        try {
            $this->invoke(root: $missing);
            self::fail('an unresolvable root must not produce a document');
        } catch (RuntimeException $exception) {
            self::assertSame(
                "The project root {$missing} does not resolve to a physical path.",
                $exception->getMessage(),
            );
        }

        self::assertSame('', $this->output->contents());
    }

    public function test_an_explicit_json_format_is_the_same_invocation_as_none(): void
    {
        $root = $this->directory();

        self::assertSame(0, $this->invoke(['--format=json'], root: $root));

        $explicit = $this->output->contents();

        $this->setUp();

        self::assertSame(0, $this->invoke(root: $root));
        self::assertSame($explicit, $this->output->contents());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rejectedInvocationProvider(): iterable
    {
        yield 'a format the command does not render' => [['--format=markdown']];
        yield 'an unknown format' => [['--format=yaml']];
        yield 'an empty format' => [['--format=']];
        yield 'a bare --format naming nothing' => [['--format']];
        yield 'a positional argument' => [['packages']];
        yield 'a positional argument beside a valid format' => [['--format=json', 'packages']];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('rejectedInvocationProvider')]
    public function test_a_rejected_invocation_exits_2_and_leaves_stdout_empty(array $argv): void
    {
        self::assertSame(2, $this->invoke($argv));
        self::assertSame('', $this->output->contents());
        self::assertStringContainsString('orbitron:inspect', $this->errorOutput->contents());
        self::assertStringContainsString('--format=json', $this->errorOutput->contents());
    }

    /**
     * Nothing is remembered between calls: a second command built over
     * different records reports the different records.
     */
    public function test_a_repeated_call_reports_the_records_it_was_given(): void
    {
        self::assertSame(0, $this->invoke(facts: [new PackageFact('kinetis/orbitron', '1.0.0', '/app')]));

        $first = $this->output->contents();

        $this->setUp();

        self::assertSame(0, $this->invoke(facts: [new PackageFact('kinetis/orbitron', '1.0.1', '/app')]));

        self::assertStringContainsString('"orbitronVersion": "1.0.0"', $first);
        self::assertStringContainsString('"orbitronVersion": "1.0.1"', $this->output->contents());
    }
}
