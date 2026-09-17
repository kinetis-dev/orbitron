<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

use JsonException;

/**
 * Whether a project's Composer layout is the one narrow layout Orbitron
 * supports: exactly one PSR-4 production namespace mapped to `src/`
 * under `autoload`, and exactly one PSR-4 test namespace mapped to
 * `tests/` under `autoload-dev`.
 *
 * That is the layout the Kinetis skeleton has, and it is deliberately
 * narrower than anything the framework itself demands: a fixed pair of
 * namespaces is what lets a later scaffold place a class without
 * guessing. `Kinetis\Cache\NamespaceScanner` asks for far less — it
 * walks every string prefix under `autoload.psr-4`, at any directory,
 * accepting array-valued mappings, and it never reads `autoload-dev` at
 * all. So a layout rejected here can still be an application whose
 * routes, commands, tools and listeners are all discovered normally.
 *
 * The production check does subsume one real discovery failure, the one
 * NamespaceScanner::warnIfNoPsr4Root() reports: a project with no usable
 * `autoload.psr-4` map has no root to scan, so discovery finds nothing
 * at all and says so through an `error_log()` line alone. Such a project
 * is `psr4_map_missing` or `path_unmapped` here, in a document that can
 * be read.
 *
 * What this proves is exactly the admitted-layout question, and nothing
 * more. An error is not a finding about discovery, and not one about
 * request isolation, non-blocking I/O, security, route uniqueness or any
 * other property of the application's own code.
 *
 * read() receives the already-detected project root and appends the one
 * fixed file name itself; no caller-selected path is ever opened. The
 * manifest is read through a bounded stream read, so an arbitrarily
 * large file never reaches memory whole.
 */
final readonly class ProjectLayout
{
    /**
     * The largest manifest that is read. One byte beyond it is requested
     * as well, and that byte alone is what tells an admitted file from an
     * oversized one — the oversize case is never read whole.
     */
    public const int MAX_MANIFEST_BYTES = 1048576;

    public const string MANIFEST_CHECK = 'composerManifest';
    public const string PRODUCTION_CHECK = 'productionNamespace';
    public const string TEST_CHECK = 'testNamespace';

    private const string MANIFEST_FILE = '/composer.json';
    private const string PRODUCTION_PATH = 'src/';
    private const string TEST_PATH = 'tests/';

    /** A namespace prefix PSR-4 admits: one or more `Segment\` parts, so non-empty and always terminated. */
    private const string PSR4_PREFIX = '/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\\\\)+$/';

    private function __construct(
        public LayoutCheck $manifest,
        public LayoutCheck $production,
        public LayoutCheck $test,
        private ?string $productionNamespace,
        private ?string $testNamespace,
    ) {}

    /**
     * @param string $projectRoot the detected consumer root, never a path a caller chose
     */
    public static function read(string $projectRoot): self
    {
        $manifest = self::readManifest($projectRoot . self::MANIFEST_FILE);

        // A manifest that could not be read makes both namespace checks
        // unanswerable. They are skipped rather than reported as failures
        // of a layout nobody has seen.
        if (is_string($manifest)) {
            return new self(
                new LayoutCheck(self::MANIFEST_CHECK, LayoutState::Error, $manifest),
                new LayoutCheck(self::PRODUCTION_CHECK, LayoutState::Skip, 'manifest_unusable'),
                new LayoutCheck(self::TEST_CHECK, LayoutState::Skip, 'manifest_unusable'),
                null,
                null,
            );
        }

        [$production, $productionCode] = self::namespaceFor($manifest, 'autoload', self::PRODUCTION_PATH);
        [$test, $testCode] = self::namespaceFor($manifest, 'autoload-dev', self::TEST_PATH);

        return new self(
            new LayoutCheck(self::MANIFEST_CHECK, LayoutState::Pass, 'manifest_read'),
            new LayoutCheck(
                self::PRODUCTION_CHECK,
                $production === null ? LayoutState::Error : LayoutState::Pass,
                $productionCode,
            ),
            new LayoutCheck(self::TEST_CHECK, $test === null ? LayoutState::Error : LayoutState::Pass, $testCode),
            $production,
            $test,
        );
    }

    /**
     * Every check, in the one order they are reported in.
     *
     * @return non-empty-list<LayoutCheck>
     */
    public function checks(): array
    {
        return [$this->manifest, $this->production, $this->test];
    }

    /**
     * The discovered namespaces, and only when the whole layout is the
     * admitted one — a half-recognized project reports none, so no caller
     * can build on one namespace while the other is unknown.
     *
     * @return array{production: string, test: string}|null
     */
    public function namespaces(): ?array
    {
        return $this->productionNamespace === null || $this->testNamespace === null
            ? null
            : ['production' => $this->productionNamespace, 'test' => $this->testNamespace];
    }

    public function hasError(): bool
    {
        foreach ($this->checks() as $check) {
            if ($check->state === LayoutState::Error) {
                return true;
            }
        }

        return false;
    }

    /**
     * The decoded manifest, or the fixed diagnostic code for why it could
     * not be used. A code names the outcome only: no file contents, no
     * exception text, no path.
     *
     * @return array<array-key, mixed>|string
     */
    private static function readManifest(string $path): array|string
    {
        if (!is_file($path)) {
            return 'manifest_missing';
        }

        // Suppressed because the diagnostic here is the returned code,
        // not a PHP warning on a STDERR that must stay empty.
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 'manifest_unreadable';
        }

        $contents = stream_get_contents($handle, self::MAX_MANIFEST_BYTES + 1);

        fclose($handle);

        if ($contents === false) {
            return 'manifest_unreadable';
        }

        // The one extra byte arrived, so the file is larger than the
        // admitted size. Nothing beyond it was ever read.
        if (strlen($contents) > self::MAX_MANIFEST_BYTES) {
            return 'manifest_oversize';
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'manifest_not_json';
        }

        // A JSON document whose root is an object begins with `{` once
        // leading whitespace is gone. Decoding alone cannot tell `{}`
        // from `[]`, since both arrive as an empty PHP array.
        if (!is_array($decoded) || !str_starts_with(ltrim($contents), '{')) {
            return 'manifest_not_an_object';
        }

        return $decoded;
    }

    /**
     * The single namespace prefix mapped to $path under $section's PSR-4
     * map, and the check code either way. Mappings to any other path are
     * left alone, array-valued ones included — a project may declare
     * whatever else it needs.
     *
     * @param array<array-key, mixed> $manifest
     * @return array{?string, string}
     */
    private static function namespaceFor(array $manifest, string $section, string $path): array
    {
        $autoload = $manifest[$section] ?? null;
        $map = is_array($autoload) ? $autoload['psr-4'] ?? null : null;

        if (!is_array($map)) {
            return [null, 'psr4_map_missing'];
        }

        $prefixes = [];

        foreach ($map as $prefix => $target) {
            // A prefix reaching the fixed path through a list of
            // directories is rejected outright rather than read as the
            // single mapping this layout admits.
            if (is_array($target) && in_array($path, $target, true)) {
                return [null, 'path_mapping_not_a_string'];
            }

            if ($target === $path) {
                $prefixes[] = (string) $prefix;
            }
        }

        if ($prefixes === []) {
            return [null, 'path_unmapped'];
        }

        if (count($prefixes) > 1) {
            return [null, 'path_ambiguous'];
        }

        return preg_match(self::PSR4_PREFIX, $prefixes[0]) === 1
            ? [$prefixes[0], 'namespace_unique']
            : [null, 'namespace_invalid'];
    }
}
