<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Console\CommandArguments;
use Kinetis\Orbitron\Console\VerifyCommand;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerifyCommandTest extends TestCase
{
    private const string SKELETON = <<<'JSON'
        {
            "autoload": {"psr-4": {"App\\": "src/"}},
            "autoload-dev": {"psr-4": {"App\\Tests\\": "tests/"}}
        }
        JSON;

    private StreamCapture $output;
    private StreamCapture $errorOutput;

    /** @var list<ProjectFixture> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $this->output = new StreamCapture();
        $this->errorOutput = new StreamCapture();
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->remove();
        }

        $this->fixtures = [];
    }

    private function fixture(?string $manifest): ProjectFixture
    {
        $fixture = new ProjectFixture($manifest);

        $this->fixtures[] = $fixture;

        return $fixture;
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
    private function invoke(?string $projectRoot, array $argv = []): int
    {
        $command = new VerifyCommand(
            new Documents(self::packages()),
            $projectRoot,
            $this->output->stream,
            $this->errorOutput->stream,
        );

        return $command->run(CommandArguments::parse($argv));
    }

    public function test_an_admitted_layout_writes_one_document_in_a_fixed_order_and_exits_0(): void
    {
        self::assertSame(0, $this->invoke($this->fixture(self::SKELETON)->root));

        self::assertSame(
            <<<'JSON'
            {
                "schemaVersion": 1,
                "orbitronVersion": "1.0.0",
                "status": "pass",
                "checks": [
                    {
                        "name": "composerManifest",
                        "state": "pass",
                        "code": "manifest_read"
                    },
                    {
                        "name": "productionNamespace",
                        "state": "pass",
                        "code": "namespace_unique"
                    },
                    {
                        "name": "testNamespace",
                        "state": "pass",
                        "code": "namespace_unique"
                    }
                ],
                "namespaces": {
                    "production": "App\\",
                    "test": "App\\Tests\\"
                }
            }

            JSON,
            $this->output->contents(),
        );
        self::assertSame('', $this->errorOutput->contents());
    }

    /**
     * A completed verification that found errors is exit 3 — its own
     * outcome, not the launcher's 1 and not a rejected invocation's 2.
     */
    public function test_a_manifest_it_cannot_use_exits_3_with_the_dependent_checks_skipped(): void
    {
        self::assertSame(3, $this->invoke($this->fixture(null)->root));

        self::assertSame(
            <<<'JSON'
            {
                "schemaVersion": 1,
                "orbitronVersion": "1.0.0",
                "status": "error",
                "checks": [
                    {
                        "name": "composerManifest",
                        "state": "error",
                        "code": "manifest_missing"
                    },
                    {
                        "name": "productionNamespace",
                        "state": "skip",
                        "code": "manifest_unusable"
                    },
                    {
                        "name": "testNamespace",
                        "state": "skip",
                        "code": "manifest_unusable"
                    }
                ],
                "namespaces": null
            }

            JSON,
            $this->output->contents(),
        );
        self::assertSame('', $this->errorOutput->contents());
    }

    public function test_a_half_recognized_layout_exits_3_and_reports_no_namespaces(): void
    {
        $manifest = '{"autoload": {"psr-4": {"App\\\\": "src/"}}}';

        self::assertSame(3, $this->invoke($this->fixture($manifest)->root));

        $document = $this->output->contents();

        self::assertStringContainsString('"status": "error"', $document);
        self::assertStringContainsString('"code": "psr4_map_missing"', $document);
        self::assertStringContainsString('"namespaces": null', $document);
        self::assertStringNotContainsString('"production"', $document);
    }

    public function test_an_explicit_json_format_is_the_same_invocation_as_none(): void
    {
        $root = $this->fixture(self::SKELETON)->root;

        self::assertSame(0, $this->invoke($root, ['--format=json']));

        $explicit = $this->output->contents();

        $this->setUp();

        self::assertSame(0, $this->invoke($root));
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
        yield 'a positional argument' => [['layout']];
        yield 'a positional path' => [['/etc']];
        yield 'a positional argument beside a valid format' => [['--format=json', 'layout']];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('rejectedInvocationProvider')]
    public function test_a_rejected_invocation_exits_2_and_leaves_stdout_empty(array $argv): void
    {
        self::assertSame(2, $this->invoke($this->fixture(self::SKELETON)->root, $argv));
        self::assertSame('', $this->output->contents());
        self::assertStringContainsString('orbitron:verify', $this->errorOutput->contents());
        self::assertStringContainsString('--format=json', $this->errorOutput->contents());
    }

    /**
     * Without an override the root comes from the framework's own
     * contract: Composer's bin-proxy global is what
     * Kinetis\Runtime\ProjectRoot::detect() reads, and the document
     * changes with it.
     */
    public function test_it_detects_the_project_root_through_the_frameworks_own_contract(): void
    {
        $fixture = $this->fixture(self::SKELETON);
        $previous = $GLOBALS['_composer_bin_dir'] ?? null;
        $GLOBALS['_composer_bin_dir'] = $fixture->root . '/vendor/bin';

        try {
            self::assertSame(0, $this->invoke(null));
        } finally {
            if ($previous === null) {
                unset($GLOBALS['_composer_bin_dir']);
            } else {
                $GLOBALS['_composer_bin_dir'] = $previous;
            }
        }

        self::assertStringContainsString('"production": "App\\\\"', $this->output->contents());
    }
}
