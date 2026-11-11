<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Console\CommandArguments;
use Kinetis\Orbitron\Console\ContextCommand;
use Kinetis\Orbitron\Context;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use Kinetis\Orbitron\Tests\Fixtures\CommandOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContextCommandTest extends TestCase
{
    private CommandOutput $output;

    protected function setUp(): void
    {
        $this->output = new CommandOutput();
    }

    private static function packages(): InstalledPackages
    {
        return new InstalledPackages([
            new PackageFact('kinetis/orbitron', '1.0.0', '/app/vendor/kinetis/orbitron'),
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
        ]);
    }

    /**
     * @param list<string> $argv
     */
    private function invoke(array $argv = []): int
    {
        $command = new ContextCommand($this->output->output, new Documents(self::packages()));

        return $command->run(CommandArguments::parse($argv));
    }

    public function test_it_writes_the_markdown_document_by_default(): void
    {
        self::assertSame(0, $this->invoke());
        self::assertSame((new Context(self::packages()))->toMarkdown(), $this->output->takeStdout());
        self::assertSame('', $this->output->takeStderr());
    }

    public function test_an_explicit_markdown_format_is_the_same_invocation_as_none(): void
    {
        self::assertSame(0, $this->invoke(['--format=markdown']));
        self::assertSame((new Context(self::packages()))->toMarkdown(), $this->output->takeStdout());
    }

    public function test_the_json_format_writes_exactly_one_document_ending_in_a_single_newline(): void
    {
        self::assertSame(0, $this->invoke(['--format=json']));

        $written = $this->output->takeStdout();

        self::assertSame(
            json_encode(
                (new Context(self::packages()))->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n",
            $written,
        );
        self::assertSame(1, substr_count($written, "\n", strlen(rtrim($written, "\n"))));
    }

    public function test_both_formats_report_the_same_facts(): void
    {
        self::assertSame(0, $this->invoke());

        $markdown = $this->output->takeStdout();

        $this->setUp();

        self::assertSame(0, $this->invoke(['--format=json']));

        $json = $this->output->takeStdout();

        foreach (['1.0.0', 'kinetis/framework', '1.11.2', 'https://kinetis.dev/docs/agent-workflow.html', 'shared database, broker or object store one at a time'] as $fact) {
            self::assertStringContainsString($fact, $markdown);
            self::assertStringContainsString($fact, $json);
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rejectedInvocationProvider(): iterable
    {
        yield 'an unknown format' => [['--format=html']];
        yield 'an empty format' => [['--format=']];
        yield 'a bare --format naming nothing' => [['--format']];
        yield 'a positional argument' => [['context']];
        yield 'a positional argument beside a valid format' => [['--format=json', 'context']];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('rejectedInvocationProvider')]
    public function test_a_rejected_invocation_exits_2_and_leaves_stdout_empty(array $argv): void
    {
        self::assertSame(2, $this->invoke($argv));
        self::assertSame('', $this->output->takeStdout());
        self::assertSame(
            "orbitron:context takes no arguments and accepts --format=markdown (the default) or --format=json.\n",
            $this->output->takeStderr(),
        );
    }
}
