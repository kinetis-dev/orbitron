<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Console\CommandArguments;
use Kinetis\Orbitron\Console\InspectCommand;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InspectCommandTest extends TestCase
{
    private StreamCapture $output;
    private StreamCapture $errorOutput;

    protected function setUp(): void
    {
        $this->output = new StreamCapture();
        $this->errorOutput = new StreamCapture();
    }

    /**
     * @param list<PackageFact>|null $facts
     * @param list<string> $argv
     */
    private function invoke(array $argv = [], ?array $facts = null): int
    {
        $command = new InspectCommand(
            new Documents(new InstalledPackages($facts ?? [
                new PackageFact('kinetis/queue', '1.3.2', '/app/vendor/kinetis/queue'),
                new PackageFact('kinetis/orbitron', '1.0.0', '/app/vendor/kinetis/orbitron'),
                new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
                new PackageFact('kinetis/replaced-by-framework', null, null),
                new PackageFact('psr/log', '3.0.2', '/app/vendor/psr/log'),
            ])),
            $this->output->stream,
            $this->errorOutput->stream,
        );

        return $command->run(CommandArguments::parse($argv));
    }

    public function test_it_writes_exactly_one_json_document_with_a_fixed_key_and_list_order(): void
    {
        self::assertSame(0, $this->invoke());

        self::assertSame(
            <<<'JSON'
            {
                "schemaVersion": 1,
                "orbitronVersion": "1.0.0",
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

    public function test_an_explicit_json_format_is_the_same_invocation_as_none(): void
    {
        self::assertSame(0, $this->invoke(['--format=json']));

        $explicit = $this->output->contents();

        $this->setUp();

        self::assertSame(0, $this->invoke());
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
