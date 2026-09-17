<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * The one construction workflow Orbitron performs: a `GET /health`
 * controller returning `{"status":"ok"}`, and a framework
 * `ApplicationTestCase` that proves the endpoint answers that same
 * document on two sequential requests.
 *
 * Nothing about it is configurable. The two paths, the route, the
 * response and both file bodies are fixed; the only thing read from the
 * project is the namespace pair {@see ProjectLayout} admits, which is
 * what lets the two classes be placed without guessing.
 *
 * preview() reads metadata and returns the plan. apply() is the only
 * write, and it trusts no earlier preview: it re-reads the manifest, the
 * directories, their symlink state and both targets immediately before
 * creating anything. The project root is a parameter of each call rather
 * than of the object, and neither call leaves a plan, a file body, a
 * diagnostic or a project identity behind.
 *
 * What it cannot answer is whether the application already routes
 * `GET /health` somewhere else: that would mean reading application
 * source, which Orbitron does not do. The generated test is what
 * surfaces the conflict, through the framework's own route discovery.
 */
final readonly class HealthScaffold
{
    /**
     * The complete write set, project-relative, in the order it is
     * written and reported.
     *
     * @var array{string, string}
     */
    public const array TARGETS = ['src/Http/HealthController.php', 'tests/Http/HealthControllerTest.php'];

    /**
     * The directories that must already exist. Orbitron creates none of
     * them.
     *
     * @var list<string>
     */
    private const array DIRECTORIES = ['src', 'src/Http', 'tests', 'tests/Http'];

    /**
     * Every filesystem refusal code, in the one order they are reported
     * in — the order the document carries, not the order the checks
     * happened to run in.
     *
     * @var list<string>
     */
    private const array REFUSALS = [
        'directory_missing',
        'directory_outside_project',
        'directory_symlinked',
        'target_exists',
    ];

    private const string CONTROLLER = <<<'SOURCE'
        <?php

        declare(strict_types=1);

        namespace %s;

        use Kinetis\Http\Attributes\Get;

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

        SOURCE;

    private const string TEST = <<<'SOURCE'
        <?php

        declare(strict_types=1);

        namespace %s;

        use Kinetis\Testing\ApplicationTestCase;

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
                $this->client->get('/health')->assertOk()->assertJson(['status' => 'ok']);
                $this->client->get('/health')->assertOk()->assertJson(['status' => 'ok']);
            }
        }

        SOURCE;

    public function __construct(
        private ScaffoldWriter $writer = new FileScaffoldWriter(),
    ) {}

    /**
     * The plan, recomputed from the project as it is now. Nothing is
     * written, and nothing about this call is carried into the next one.
     *
     * @param string $projectRoot the detected consumer root, never a path a caller chose
     */
    public function preview(string $projectRoot): ScaffoldOutcome
    {
        [$codes] = self::resolve($projectRoot);

        return $codes === []
            ? new ScaffoldOutcome(ScaffoldMode::Preview, ScaffoldStatus::Ready, ['scaffold_ready'], [])
            : new ScaffoldOutcome(ScaffoldMode::Preview, ScaffoldStatus::Refused, $codes, []);
    }

    /**
     * Creates both files, or leaves the project exactly as it was.
     *
     * @param string $projectRoot the detected consumer root, never a path a caller chose
     */
    public function apply(string $projectRoot): ScaffoldOutcome
    {
        [$codes, $files] = self::resolve($projectRoot);

        if ($codes !== []) {
            return new ScaffoldOutcome(ScaffoldMode::Apply, ScaffoldStatus::Refused, $codes, []);
        }

        /** @var list<array{absolute: string, relative: string, contents: string}> $created */
        $created = [];

        foreach ($files as $file) {
            $handle = $this->writer->create($file['absolute']);

            // A create that failed opened nothing, so whatever sits at
            // that path is not this invocation's and is left alone.
            if ($handle === null) {
                return $this->rollBack($created);
            }

            $created[] = $file;

            if (!$this->writeThrough($handle, $file['contents'])) {
                return $this->rollBack($created);
            }
        }

        return new ScaffoldOutcome(ScaffoldMode::Apply, ScaffoldStatus::Created, ['scaffold_created'], []);
    }

    /**
     * Writes every byte, or reports that it did not. The handle is
     * closed on each path out, and a flush failure still closes.
     *
     * @param resource $handle
     */
    private function writeThrough(mixed $handle, string $contents): bool
    {
        $length = strlen($contents);
        $written = 0;

        while ($written < $length) {
            $chunk = $this->writer->write($handle, substr($contents, $written));

            // A short write is written again from where it stopped. A
            // failed one, and one that accepted nothing at all, are both
            // the end of this file: the second would otherwise spin.
            if ($chunk === null || $chunk === 0) {
                $this->writer->close($handle);

                return false;
            }

            $written += $chunk;
        }

        $flushed = $this->writer->flush($handle);
        $closed = $this->writer->close($handle);

        return $flushed && $closed;
    }

    /**
     * Removes every file this invocation created, and reports the ones
     * that survived removal.
     *
     * @param list<array{absolute: string, relative: string, contents: string}> $created
     */
    private function rollBack(array $created): ScaffoldOutcome
    {
        $remaining = [];

        foreach ($created as $file) {
            if (!$this->writer->remove($file['absolute'])) {
                $remaining[] = $file['relative'];
            }
        }

        return new ScaffoldOutcome(
            ScaffoldMode::Apply,
            ScaffoldStatus::Failed,
            ['write_failed', $remaining === [] ? 'rolled_back' : 'rollback_failed'],
            $remaining,
        );
    }

    /**
     * Every precondition, read at the moment of the call: the admitted
     * layout, the physical project root, the four fixed directories, and
     * both targets. Exactly one half of the pair is non-empty — the
     * refusal codes, or the files to create.
     *
     * @return array{list<string>, list<array{absolute: string, relative: string, contents: string}>}
     */
    private static function resolve(string $projectRoot): array
    {
        $layout = ProjectLayout::read($projectRoot);
        $namespaces = $layout->namespaces();

        // Without both namespaces there is nowhere to put either class,
        // so the shared reader's own codes are the whole answer.
        if ($namespaces === null) {
            return [self::layoutCodes($layout), []];
        }

        $root = realpath($projectRoot);

        // The manifest was read out of this root a moment ago, so a root
        // that no longer resolves is one that went away underneath that
        // read — and the four directories went with it.
        if ($root === false) {
            return [['directory_missing'], []];
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $found = [];

        foreach (self::DIRECTORIES as $directory) {
            $code = self::directoryRefusal($prefix, $directory);

            if ($code !== null) {
                $found[$code] = true;
            }
        }

        foreach (self::TARGETS as $target) {
            $path = self::physical($prefix, $target);

            // is_link() as well as file_exists(), which follows a symlink
            // and therefore answers false for one pointing at nothing —
            // a path that is still occupied, and still a collision.
            if (file_exists($path) || is_link($path)) {
                $found['target_exists'] = true;
            }
        }

        $codes = array_values(array_filter(self::REFUSALS, static fn (string $code): bool => isset($found[$code])));

        if ($codes !== []) {
            return [$codes, []];
        }

        return [[], [
            [
                'absolute' => self::physical($prefix, self::TARGETS[0]),
                'relative' => self::TARGETS[0],
                'contents' => sprintf(self::CONTROLLER, self::classNamespace($namespaces['production'])),
            ],
            [
                'absolute' => self::physical($prefix, self::TARGETS[1]),
                'relative' => self::TARGETS[1],
                'contents' => sprintf(self::TEST, self::classNamespace($namespaces['test'])),
            ],
        ]];
    }

    /**
     * Why one fixed directory cannot be written into, or null when it
     * can.
     *
     * Containment is checked against the resolved path rather than the
     * one that was built, which is what a symlinked component would
     * otherwise escape through; a link that stays inside the project is
     * still refused, because the write set has to be the two paths the
     * document names.
     */
    private static function directoryRefusal(string $prefix, string $directory): ?string
    {
        $path = self::physical($prefix, $directory);

        if (!is_dir($path)) {
            return 'directory_missing';
        }

        $real = realpath($path);

        if ($real === false) {
            return 'directory_missing';
        }

        if (!str_starts_with($real, $prefix)) {
            return 'directory_outside_project';
        }

        return is_link($path) ? 'directory_symlinked' : null;
    }

    /**
     * The codes of every layout check that did not pass, in the order
     * the reader reports them.
     *
     * @return list<string>
     */
    private static function layoutCodes(ProjectLayout $layout): array
    {
        $codes = [];

        foreach ($layout->checks() as $check) {
            if ($check->state !== LayoutState::Pass) {
                $codes[] = $check->code;
            }
        }

        return $codes;
    }

    /**
     * The `Http` namespace under one admitted PSR-4 prefix: `App\` gives
     * `App\Http`, matching the `src/Http` and `tests/Http` directories
     * the two files are written into.
     */
    private static function classNamespace(string $psr4Prefix): string
    {
        return rtrim($psr4Prefix, '\\') . '\\Http';
    }

    private static function physical(string $prefix, string $relative): string
    {
        return $prefix . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
