<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Console\CommandArguments;
use Kinetis\Orbitron\Console\ScaffoldCommand;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\HealthScaffold;
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The document `orbitron:scaffold` writes, and the exit code beside it.
 */
final class ScaffoldCommandTest extends TestCase
{
    private StreamCapture $output;
    private StreamCapture $errorOutput;

    /** @var list<ScaffoldProject> */
    private array $projects = [];

    protected function setUp(): void
    {
        $this->output = new StreamCapture();
        $this->errorOutput = new StreamCapture();
    }

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->remove();
        }

        $this->projects = [];
    }

    private function project(bool $manifest = true): ScaffoldProject
    {
        $project = new ScaffoldProject($manifest);

        $this->projects[] = $project;

        return $project;
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
    private function invoke(?string $projectRoot, array $argv = [], ?HealthScaffold $scaffold = null): int
    {
        $command = new ScaffoldCommand(
            new Documents(self::packages(), $scaffold ?? new HealthScaffold()),
            $projectRoot,
            $this->output->stream,
            $this->errorOutput->stream,
        );

        return $command->run(CommandArguments::parse($argv));
    }

    public function test_a_preview_writes_the_plan_in_a_fixed_order_and_exits_0(): void
    {
        $project = $this->project();
        $before = $project->tree();

        self::assertSame(0, $this->invoke($project->root));
        self::assertSame(
            <<<'JSON'
            {
                "schemaVersion": 1,
                "orbitronVersion": "1.0.0",
                "mode": "preview",
                "status": "ready",
                "codes": [
                    "scaffold_ready"
                ],
                "targets": [
                    "src/Http/HealthController.php",
                    "tests/Http/HealthControllerTest.php"
                ],
                "remainingFiles": []
            }

            JSON,
            $this->output->contents(),
        );
        self::assertSame('', $this->errorOutput->contents());
        self::assertSame($before, $project->tree());
    }

    public function test_an_apply_writes_the_completed_result_and_exits_0(): void
    {
        $project = $this->project();

        self::assertSame(0, $this->invoke($project->root, ['--apply']));
        self::assertSame(
            <<<'JSON'
            {
                "schemaVersion": 1,
                "orbitronVersion": "1.0.0",
                "mode": "apply",
                "status": "created",
                "codes": [
                    "scaffold_created"
                ],
                "targets": [
                    "src/Http/HealthController.php",
                    "tests/Http/HealthControllerTest.php"
                ],
                "remainingFiles": []
            }

            JSON,
            $this->output->contents(),
        );
        self::assertSame('', $this->errorOutput->contents());
        self::assertFileExists($project->path('src/Http/HealthController.php'));
        self::assertFileExists($project->path('tests/Http/HealthControllerTest.php'));
    }

    /**
     * A completed operation that refused is exit 3 — its own outcome, not
     * the launcher's 1 and not a rejected invocation's 2.
     */
    public function test_a_refusal_writes_the_document_it_refused_with_and_exits_3(): void
    {
        self::assertSame(3, $this->invoke($this->project(manifest: false)->root));
        self::assertSame(
            <<<'JSON'
            {
                "schemaVersion": 1,
                "orbitronVersion": "1.0.0",
                "mode": "preview",
                "status": "refused",
                "codes": [
                    "manifest_missing",
                    "manifest_unusable",
                    "manifest_unusable"
                ],
                "targets": [
                    "src/Http/HealthController.php",
                    "tests/Http/HealthControllerTest.php"
                ],
                "remainingFiles": []
            }

            JSON,
            $this->output->contents(),
        );
        self::assertSame('', $this->errorOutput->contents());
    }

    public function test_a_failed_write_whose_rollback_failed_names_what_may_remain_and_exits_3(): void
    {
        $project = $this->project();
        $writer = new FaultyScaffoldWriter();
        $writer->failCloseAt = 2;
        $writer->failRemoveAt = [2];

        self::assertSame(3, $this->invoke($project->root, ['--apply'], new HealthScaffold($writer)));
        self::assertSame(
            <<<'JSON'
            {
                "schemaVersion": 1,
                "orbitronVersion": "1.0.0",
                "mode": "apply",
                "status": "failed",
                "codes": [
                    "write_failed",
                    "rollback_failed"
                ],
                "targets": [
                    "src/Http/HealthController.php",
                    "tests/Http/HealthControllerTest.php"
                ],
                "remainingFiles": [
                    "tests/Http/HealthControllerTest.php"
                ]
            }

            JSON,
            $this->output->contents(),
        );
    }

    public function test_an_explicit_json_format_is_the_same_invocation_as_none(): void
    {
        $root = $this->project()->root;

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
        yield 'a positional argument' => [['health']];
        yield 'a positional path' => [['/etc']];
        yield 'a positional argument beside --apply' => [['--apply', 'health']];
        yield 'an --apply carrying a value' => [['--apply=yes']];
        yield 'an --apply carrying an empty value' => [['--apply=']];
        yield 'an --apply carrying a refusal' => [['--apply=false']];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('rejectedInvocationProvider')]
    public function test_a_rejected_invocation_exits_2_and_writes_nothing(array $argv): void
    {
        $project = $this->project();
        $before = $project->tree();

        self::assertSame(2, $this->invoke($project->root, $argv));
        self::assertSame('', $this->output->contents());
        self::assertStringContainsString('orbitron:scaffold', $this->errorOutput->contents());
        self::assertStringContainsString('--apply', $this->errorOutput->contents());
        self::assertSame($before, $project->tree());
    }

    /**
     * Without an override the root comes from the framework's own
     * contract, exactly as `orbitron:verify` takes it.
     */
    public function test_it_detects_the_project_root_through_the_frameworks_own_contract(): void
    {
        $project = $this->project();
        $previous = $GLOBALS['_composer_bin_dir'] ?? null;
        $GLOBALS['_composer_bin_dir'] = $project->root . '/vendor/bin';

        try {
            self::assertSame(0, $this->invoke(null, ['--apply']));
        } finally {
            if ($previous === null) {
                unset($GLOBALS['_composer_bin_dir']);
            } else {
                $GLOBALS['_composer_bin_dir'] = $previous;
            }
        }

        self::assertStringContainsString('"status": "created"', $this->output->contents());
        self::assertFileExists($project->path('src/Http/HealthController.php'));
    }
}
