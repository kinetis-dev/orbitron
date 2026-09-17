<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Orbitron\HealthScaffold;
use Kinetis\Orbitron\ScaffoldMode;
use Kinetis\Orbitron\ScaffoldOutcome;
use Kinetis\Orbitron\ScaffoldStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * What the scaffold writes, what it refuses to write, and what it leaves
 * behind when a write does not finish.
 */
final class HealthScaffoldTest extends TestCase
{
    private const string CONTROLLER = 'src/Http/HealthController.php';
    private const string TEST = 'tests/Http/HealthControllerTest.php';

    /** @var list<ScaffoldProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->remove();
        }

        $this->projects = [];
    }

    private function project(bool $manifest = true, bool $directories = true): ScaffoldProject
    {
        $project = new ScaffoldProject($manifest, $directories);

        $this->projects[] = $project;

        return $project;
    }

    private static function assertRefused(ScaffoldOutcome $outcome, ScaffoldMode $mode, string ...$codes): void
    {
        self::assertSame($mode, $outcome->mode);
        self::assertSame(ScaffoldStatus::Refused, $outcome->status);
        self::assertSame($codes, $outcome->codes);
        self::assertSame([], $outcome->remainingFiles);
        self::assertFalse($outcome->succeeded());
    }

    public function test_a_preview_of_an_admitted_project_is_ready_and_changes_nothing(): void
    {
        $project = $this->project();
        $before = $project->tree();

        $outcome = (new HealthScaffold())->preview($project->root);

        self::assertSame(ScaffoldMode::Preview, $outcome->mode);
        self::assertSame(ScaffoldStatus::Ready, $outcome->status);
        self::assertSame(['scaffold_ready'], $outcome->codes);
        self::assertSame([], $outcome->remainingFiles);
        self::assertTrue($outcome->succeeded());
        self::assertSame($before, $project->tree());
    }

    public function test_an_apply_creates_exactly_two_files_with_the_namespaces_the_manifest_declares(): void
    {
        $project = $this->project();

        $outcome = (new HealthScaffold())->apply($project->root);

        self::assertSame(ScaffoldMode::Apply, $outcome->mode);
        self::assertSame(ScaffoldStatus::Created, $outcome->status);
        self::assertSame(['scaffold_created'], $outcome->codes);
        self::assertSame([], $outcome->remainingFiles);
        self::assertTrue($outcome->succeeded());

        self::assertSame(
            [
                'composer.json',
                'src',
                'src/Http',
                'src/Http/HealthController.php',
                'tests',
                'tests/Http',
                'tests/Http/HealthControllerTest.php',
            ],
            $project->tree(),
        );

        $namespace = rtrim($project->production, '\\') . '\\Http';

        self::assertSame(
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Kinetis\\Http\\Attributes\\Get;

            final readonly class HealthController
            {
                /**
                 * @return array{status: string}
                 */
                #[Get('/health')]
                public function show(): array
                {
                    return ['status' => 'ok'];
                }
            }

            PHP,
            $project->contents(self::CONTROLLER),
        );

        $testNamespace = rtrim($project->test, '\\') . '\\Http';

        self::assertSame(
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$testNamespace};

            use Kinetis\\Testing\\ApplicationTestCase;

            final class HealthControllerTest extends ApplicationTestCase
            {
                protected function projectRoot(): string
                {
                    return dirname(__DIR__, 2);
                }

                /**
                 * The same request twice against one booted application. A
                 * runtime that keeps the application resident answers the second
                 * request from whatever the first left behind, so a document
                 * that holds only the first time is a leak.
                 */
                public function test_the_health_endpoint_answers_the_same_document_twice(): void
                {
                    \$this->client->get('/health')->assertOk()->assertJson(['status' => 'ok']);
                    \$this->client->get('/health')->assertOk()->assertJson(['status' => 'ok']);
                }
            }

            PHP,
            $project->contents(self::TEST),
        );
    }

    /**
     * Generated code belongs to the application, and an application that
     * removes Orbitron keeps it working.
     */
    public function test_neither_generated_file_names_orbitron(): void
    {
        $project = $this->project();

        (new HealthScaffold())->apply($project->root);

        self::assertStringNotContainsStringIgnoringCase('orbitron', $project->contents(self::CONTROLLER));
        self::assertStringNotContainsStringIgnoringCase('orbitron', $project->contents(self::TEST));
    }

    /**
     * The preview is not a plan the apply then trusts: a target that
     * appeared in between is read at the moment of the apply.
     */
    public function test_an_apply_re_reads_a_collision_that_appeared_after_the_preview(): void
    {
        $project = $this->project();
        $scaffold = new HealthScaffold();

        self::assertSame(ScaffoldStatus::Ready, $scaffold->preview($project->root)->status);

        file_put_contents($project->path(self::CONTROLLER), "<?php // someone else's\n");

        self::assertRefused($scaffold->apply($project->root), ScaffoldMode::Apply, 'target_exists');

        // The pre-existing file is not this invocation's to touch, and
        // the second target was never reached.
        self::assertSame("<?php // someone else's\n", $project->contents(self::CONTROLLER));
        self::assertFileDoesNotExist($project->path(self::TEST));
    }

    public function test_a_missing_manifest_is_refused_with_the_shared_layout_readers_own_codes(): void
    {
        $project = $this->project(manifest: false);

        self::assertRefused(
            (new HealthScaffold())->preview($project->root),
            ScaffoldMode::Preview,
            'manifest_missing',
            'manifest_unusable',
            'manifest_unusable',
        );
    }

    public function test_a_half_recognized_layout_is_refused_by_the_check_that_failed(): void
    {
        $project = $this->project();
        $project->writeManifest('{"autoload": {"psr-4": {"App\\\\": "src/"}}}');

        self::assertRefused(
            (new HealthScaffold())->apply($project->root),
            ScaffoldMode::Apply,
            'psr4_map_missing',
        );
        self::assertFileDoesNotExist($project->path(self::CONTROLLER));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixedDirectoryProvider(): iterable
    {
        foreach (ScaffoldProject::DIRECTORIES as $directory) {
            yield $directory => [$directory];
        }
    }

    /**
     * Orbitron creates no directory, so each of the four is a
     * precondition rather than something the apply fills in.
     */
    #[DataProvider('fixedDirectoryProvider')]
    public function test_a_missing_fixed_directory_is_refused_without_a_write(string $directory): void
    {
        $project = $this->project();

        // Deepest first, so removing `src` also removes the `src/Http`
        // that would otherwise keep it from going.
        foreach (array_reverse(ScaffoldProject::DIRECTORIES) as $existing) {
            if (str_starts_with($existing, $directory)) {
                rmdir($project->path($existing));
            }
        }

        $before = $project->tree();

        self::assertRefused((new HealthScaffold())->apply($project->root), ScaffoldMode::Apply, 'directory_missing');
        self::assertSame($before, $project->tree());
    }

    public function test_a_symlinked_directory_inside_the_project_is_refused(): void
    {
        $project = $this->project();

        mkdir($project->path('elsewhere'), 0o700);
        rmdir($project->path('src/Http'));
        symlink($project->path('elsewhere'), $project->path('src/Http'));

        self::assertRefused((new HealthScaffold())->apply($project->root), ScaffoldMode::Apply, 'directory_symlinked');
        self::assertSame([], glob($project->path('elsewhere/*')) ?: []);
    }

    /**
     * Containment is read off the resolved path, which is the only thing
     * a link out of the project cannot forge.
     */
    public function test_a_directory_resolving_outside_the_project_is_refused(): void
    {
        $project = $this->project();
        $outside = $this->project(manifest: false, directories: false);

        rmdir($project->path('src/Http'));
        symlink($outside->root, $project->path('src/Http'));

        self::assertRefused(
            (new HealthScaffold())->apply($project->root),
            ScaffoldMode::Apply,
            'directory_outside_project',
        );
        self::assertSame([], $outside->tree());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targetProvider(): iterable
    {
        yield 'controller' => [self::CONTROLLER];
        yield 'test' => [self::TEST];
    }

    #[DataProvider('targetProvider')]
    public function test_an_existing_target_is_refused(string $target): void
    {
        $project = $this->project();

        file_put_contents($project->path($target), '');

        self::assertRefused((new HealthScaffold())->apply($project->root), ScaffoldMode::Apply, 'target_exists');
    }

    /**
     * file_exists() follows a symlink and answers false for one pointing
     * at nothing, while the path itself is still occupied.
     */
    #[DataProvider('targetProvider')]
    public function test_a_dangling_symlink_at_a_target_is_an_existing_collision(string $target): void
    {
        $project = $this->project();

        symlink($project->path('never-created'), $project->path($target));

        self::assertFileDoesNotExist($project->path($target));
        self::assertRefused((new HealthScaffold())->apply($project->root), ScaffoldMode::Apply, 'target_exists');
    }

    public function test_every_refusal_a_project_earns_is_reported_once_in_a_fixed_order(): void
    {
        $project = $this->project();

        rmdir($project->path('tests/Http'));
        rmdir($project->path('src/Http'));
        symlink($project->root . '/src', $project->path('src/Http'));
        file_put_contents($project->path(self::CONTROLLER), '');

        self::assertRefused(
            (new HealthScaffold())->apply($project->root),
            ScaffoldMode::Apply,
            'directory_missing',
            'directory_symlinked',
            'target_exists',
        );
    }

    public function test_a_short_write_is_written_again_from_where_it_stopped(): void
    {
        $project = $this->project();
        $writer = new FaultyScaffoldWriter();
        $writer->chunk = 7;

        $outcome = (new HealthScaffold($writer))->apply($project->root);

        self::assertSame(ScaffoldStatus::Created, $outcome->status);
        self::assertGreaterThan(2, $writer->writes);
        self::assertSame(0, $writer->openHandles);
        self::assertStringEndsWith("}\n", $project->contents(self::CONTROLLER));
        self::assertStringEndsWith("}\n", $project->contents(self::TEST));
    }

    /**
     * @return iterable<string, array{callable(FaultyScaffoldWriter): void}>
     */
    public static function firstFileFailureProvider(): iterable
    {
        yield 'a write that accepts nothing' => [static function (FaultyScaffoldWriter $writer): void {
            $writer->chunk = 0;
        }];
        yield 'a write that fails outright' => [static function (FaultyScaffoldWriter $writer): void {
            $writer->failWriteAt = 1;
        }];
        yield 'a create that fails' => [static function (FaultyScaffoldWriter $writer): void {
            $writer->failCreateAt = 1;
        }];
    }

    /**
     * @param callable(FaultyScaffoldWriter): void $arrange
     */
    #[DataProvider('firstFileFailureProvider')]
    public function test_a_first_file_that_cannot_be_written_leaves_the_project_as_it_was(callable $arrange): void
    {
        $project = $this->project();
        $before = $project->tree();
        $writer = new FaultyScaffoldWriter();
        $arrange($writer);

        $outcome = (new HealthScaffold($writer))->apply($project->root);

        self::assertSame(ScaffoldMode::Apply, $outcome->mode);
        self::assertSame(ScaffoldStatus::Failed, $outcome->status);
        self::assertSame(['write_failed', 'rolled_back'], $outcome->codes);
        self::assertSame([], $outcome->remainingFiles);
        self::assertFalse($outcome->succeeded());
        self::assertSame($before, $project->tree());
        self::assertSame(0, $writer->openHandles);
    }

    /**
     * @return iterable<string, array{callable(FaultyScaffoldWriter): void, int}>
     */
    public static function secondFileFailureProvider(): iterable
    {
        yield 'a create that fails' => [static function (FaultyScaffoldWriter $writer): void {
            $writer->failCreateAt = 2;
        }, 1];
        yield 'a write that fails outright' => [static function (FaultyScaffoldWriter $writer): void {
            $writer->failWriteAt = 2;
        }, 2];
        yield 'a flush that fails' => [static function (FaultyScaffoldWriter $writer): void {
            $writer->failFlushAt = 2;
        }, 2];
        yield 'a close that fails' => [static function (FaultyScaffoldWriter $writer): void {
            $writer->failCloseAt = 2;
        }, 2];
    }

    /**
     * @param callable(FaultyScaffoldWriter): void $arrange
     */
    #[DataProvider('secondFileFailureProvider')]
    public function test_a_second_file_that_cannot_be_written_removes_what_this_invocation_created(
        callable $arrange,
        int $removals,
    ): void {
        $project = $this->project();
        $before = $project->tree();
        $writer = new FaultyScaffoldWriter();
        $arrange($writer);

        $outcome = (new HealthScaffold($writer))->apply($project->root);

        self::assertSame(ScaffoldStatus::Failed, $outcome->status);
        self::assertSame(['write_failed', 'rolled_back'], $outcome->codes);
        self::assertSame([], $outcome->remainingFiles);
        self::assertCount($removals, $writer->removed);
        self::assertSame($before, $project->tree());
        self::assertSame(0, $writer->openHandles);
    }

    /**
     * @return iterable<string, array{list<int>, list<string>}>
     */
    public static function rollbackFailureProvider(): iterable
    {
        yield 'the controller survives removal' => [[1], [self::CONTROLLER]];
        yield 'the test survives removal' => [[2], [self::TEST]];
        yield 'neither can be removed' => [[1, 2], [self::CONTROLLER, self::TEST]];
    }

    /**
     * @param list<int> $failRemoveAt
     * @param list<string> $remaining
     */
    #[DataProvider('rollbackFailureProvider')]
    public function test_a_rollback_that_fails_names_exactly_the_files_that_may_remain(
        array $failRemoveAt,
        array $remaining,
    ): void {
        $project = $this->project();
        $writer = new FaultyScaffoldWriter();
        $writer->failCloseAt = 2;
        $writer->failRemoveAt = $failRemoveAt;

        $outcome = (new HealthScaffold($writer))->apply($project->root);

        self::assertSame(ScaffoldStatus::Failed, $outcome->status);
        self::assertSame(['write_failed', 'rollback_failed'], $outcome->codes);
        self::assertSame($remaining, $outcome->remainingFiles);

        foreach ($remaining as $relative) {
            self::assertFileExists($project->path($relative));
        }
    }

    /**
     * One instance, two projects: the second answer is the second
     * project's, and nothing of the first survives the call.
     */
    public function test_the_service_carries_nothing_from_one_call_into_the_next(): void
    {
        $scaffold = new HealthScaffold();
        $ready = $this->project();
        $refused = $this->project(manifest: false);

        self::assertSame(ScaffoldStatus::Created, $scaffold->apply($ready->root)->status);
        self::assertRefused(
            $scaffold->preview($refused->root),
            ScaffoldMode::Preview,
            'manifest_missing',
            'manifest_unusable',
            'manifest_unusable',
        );
        self::assertSame(ScaffoldStatus::Refused, $scaffold->preview($ready->root)->status);

        $properties = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(HealthScaffold::class))->getProperties(),
        );

        self::assertSame(['writer'], $properties);
    }
}
