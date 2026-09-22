<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * One window, one bounded literal search, or one directory listing, of
 * one real installed, non-root Composer package, as the JSON document
 * the MCP tools return.
 *
 * The documentation pages Orbitron serves are published from main and
 * can describe behavior newer than a project has installed. This reader
 * is the authority for what the project actually runs: the source on
 * disk, at the version {@see InstalledPackages} reports, read live on
 * every call. That authority is why the set is not narrowed to
 * `kinetis/*`: an exact installed dependency governs the behavior
 * Kinetis builds on, and its own source is the only proof of it. The
 * application's own checkout is not in the set — the Composer root is
 * excluded upstream — so this remains bounded evidence of what is
 * installed, not a file browser.
 *
 * Nothing here is chosen by a caller except the package name, the
 * relative path, and the window or the query. All three calls reach the
 * filesystem through one resolver: the install root comes from
 * Composer's own installed set and is the whole read boundary; the path
 * is admitted by its syntax alone before anything is opened; and the
 * resolved target must satisfy that same syntax and still lie under
 * that same root. A symlink is therefore resolved and then re-admitted,
 * so one pointing out of the package, or at a name this tool does not
 * serve, is refused rather than followed — and a listing re-admits each
 * child the same way, so it reports only what a read of that name could
 * reach. A refusal names a fixed code and nothing else — no resolved
 * path, no exception text, no content.
 *
 * Both bounds are enforced by construction: a read is one stream read of
 * the admitted size plus one byte, and that byte alone tells an admitted
 * file from an oversized one; a listing stops at the child past the
 * admitted count and reports no partial directory. Nothing is retained
 * between calls.
 */
final readonly class PackageSourceReader
{
    /**
     * The largest file that is read. One byte beyond it is requested as
     * well, and the oversized case is never read whole.
     */
    public const int MAX_SOURCE_BYTES = 1048576;

    /** The most lines one call returns, which is also the default. */
    public const int MAX_LINE_COUNT = 200;

    /** The longest path a call may name, counted in Unicode characters. */
    public const int MAX_PATH_LENGTH = 256;

    /** The longest query a search may name, counted in Unicode characters. */
    public const int MAX_QUERY_LENGTH = 256;

    /** The most matches one search returns. */
    public const int MAX_MATCH_COUNT = 50;

    /** The most entries one listing returns; the child past it refuses the call. */
    public const int MAX_ENTRY_COUNT = 200;

    /**
     * The one path that names the package root itself. Only a listing
     * takes it: it is where a caller starts when the layout is unknown,
     * and there is no file behind it for a read to open.
     */
    private const string ROOT = '.';

    public function __construct(private InstalledPackages $packages) {}

    /**
     * The window, or the refusal. Both are documents; the refusal is the
     * failed one.
     *
     * @param string $path relative `/` syntax, admitted by {@see load()}
     *        before any lookup result is turned into a filesystem operation
     * @param int $startLine one-based, already validated by the adapter
     * @param int $lineCount already validated by the adapter to 1..{@see MAX_LINE_COUNT}
     */
    public function read(string $package, string $path, int $startLine, int $lineCount): Document
    {
        $loaded = $this->load($package, $path);

        if ($loaded instanceof Document) {
            return $loaded;
        }

        [$version, $lines] = $loaded;
        $total = count($lines);

        if ($startLine > $total) {
            return self::refuse('line_out_of_range');
        }

        $window = array_slice($lines, $startLine - 1, $lineCount);
        $endLine = $startLine + count($window) - 1;

        return new Document([
            'status' => 'ok',
            'package' => $package,
            'version' => $version,
            'path' => $path,
            'startLine' => $startLine,
            'endLine' => $endLine,
            'hasMore' => $endLine < $total,
            'content' => implode('', $window),
        ], failed: false);
    }

    /**
     * The lines from $startLine that contain $query, up to
     * {@see MAX_MATCH_COUNT} of them, or the refusal. Finding none is a
     * successful search with an empty list, not a refusal.
     *
     * The scan is literal and case-sensitive, and each line is compared
     * as it is reported — without its terminator — so a query carrying
     * a line ending matches nothing rather than the end of a line.
     *
     * Scanning stops at the first match past the cap. `hasMore` says
     * another one exists; the caller continues from the last reported
     * line plus one, which is why no cursor is returned.
     *
     * @param string $path relative `/` syntax, admitted by {@see load()}
     *        before any lookup result is turned into a filesystem operation
     * @param string $query non-empty, already validated by the adapter to
     *        at most {@see MAX_QUERY_LENGTH} characters
     * @param int $startLine one-based, already validated by the adapter
     */
    public function search(string $package, string $path, string $query, int $startLine): Document
    {
        $loaded = $this->load($package, $path);

        if ($loaded instanceof Document) {
            return $loaded;
        }

        [$version, $lines] = $loaded;
        $total = count($lines);

        if ($startLine > $total) {
            return self::refuse('line_out_of_range');
        }

        /** @var list<array{line: int, content: string}> $matches */
        $matches = [];
        $hasMore = false;

        for ($line = $startLine; $line <= $total; $line++) {
            $content = $lines[$line - 1];

            // The terminator is the file's, not the line's, and a line
            // carries at most one because the split point follows it.
            if (str_ends_with($content, "\n")) {
                $content = substr($content, 0, str_ends_with($content, "\r\n") ? -2 : -1);
            }

            if (!str_contains($content, $query)) {
                continue;
            }

            // The match past the cap is the only reason the scan runs on
            // this far: it answers hasMore, and it is not reported.
            if (count($matches) === self::MAX_MATCH_COUNT) {
                $hasMore = true;

                break;
            }

            $matches[] = ['line' => $line, 'content' => $content];
        }

        return new Document([
            'status' => 'ok',
            'package' => $package,
            'version' => $version,
            'path' => $path,
            'query' => $query,
            'startLine' => $startLine,
            'matches' => $matches,
            'hasMore' => $hasMore,
        ], failed: false);
    }

    /**
     * The direct children of one admitted directory — the name and the
     * kind of each, in bytewise name order — or the refusal.
     *
     * This is where a file is found when the package is known and the
     * path is not; it is not a search. It descends into
     * nothing, so what comes back is one directory as it is, and a
     * subdirectory is listed by naming it in the next call.
     *
     * A child is reported only when both its own name and the target it
     * resolves to are admitted under the same install root, which is the
     * rule {@see read()} applies to the path it is given: a hidden entry,
     * the package's own top-level `vendor` tree, a link out of the
     * package, a link to either of those, a dangling one, and a socket,
     * device or fifo are left out rather than offered as something to
     * read next.
     *
     * The count is the bound. A directory whose reportable children pass
     * {@see MAX_ENTRY_COUNT} refuses whole, because a prefix of a
     * directory silently answers "what is in here" with something else.
     *
     * @param string $path relative `/` syntax naming a directory under
     *        the package root, or {@see ROOT} for the root itself
     */
    public function list(string $package, string $path): Document
    {
        $resolved = $this->resolve($package, $path, listing: true);

        if ($resolved instanceof Document) {
            return $resolved;
        }

        // An admitted path behind a regular file is a call that named
        // the wrong kind of thing, not one that named something unserved.
        if (!is_dir($resolved['target'])) {
            return self::refuse('source_not_directory');
        }

        $entries = self::children($resolved['root'], $resolved['target']);

        if ($entries instanceof Document) {
            return $entries;
        }

        // The order is this tool's own, and bytewise: the same directory
        // comes back the same way on every platform and filesystem,
        // whatever order the entries were read in.
        usort($entries, static fn (array $first, array $second): int => strcmp($first['name'], $second['name']));

        return new Document([
            'status' => 'ok',
            'package' => $package,
            'version' => $resolved['version'],
            'path' => $path,
            'entries' => $entries,
        ], failed: false);
    }

    /**
     * The installed version and the lines of the one admitted file, or
     * the refusal that stopped the call before it became one.
     *
     * A window and a search are admitted, confined, bounded and validated
     * identically — there is one path to a file, not one per tool — and
     * everything a listing shares with them lives in {@see resolve()}.
     * What remains here is the file work itself.
     *
     * @return array{string, list<string>}|Document
     */
    private function load(string $package, string $path): array|Document
    {
        $resolved = $this->resolve($package, $path, listing: false);

        if ($resolved instanceof Document) {
            return $resolved;
        }

        $target = $resolved['target'];

        // A directory or any other non-regular target is refused here
        // rather than opened.
        if (!is_file($target)) {
            return self::refuse('source_unreadable');
        }

        // Suppressed because the diagnostic is the returned code, not a
        // PHP warning on a stdout that carries JSON-RPC frames.
        $handle = @fopen($target, 'rb');

        if ($handle === false) {
            return self::refuse('source_unreadable');
        }

        $contents = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);

        fclose($handle);

        if ($contents === false) {
            return self::refuse('source_unreadable');
        }

        // The extra byte arrived, so the file is larger than the
        // admitted size. Nothing beyond it was ever read.
        if (strlen($contents) > self::MAX_SOURCE_BYTES) {
            return self::refuse('source_oversize');
        }

        // A NUL byte or an invalid encoding means this is not the source
        // text the tool reports; `//u` decides UTF-8 without mbstring.
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            return self::refuse('source_not_text');
        }

        // Split after each newline, so every line keeps its own ending,
        // CRLF included, and a file with no final newline keeps that.
        // A split point only ever follows a newline, so the sole empty
        // piece PREG_SPLIT_NO_EMPTY can drop is the one past a trailing
        // newline.
        $lines = preg_split('/(?<=\n)/', $contents, flags: PREG_SPLIT_NO_EMPTY);
        \assert(is_array($lines));

        return [$resolved['version'], $lines];
    }

    /**
     * The install root, the resolved target and the version behind one
     * admitted path, or the refusal that stopped the call.
     *
     * Every rule that bounds what this package serves lives here, so a
     * read and a listing are admitted and confined identically and
     * differ only in what they do with the target. Admitting the path
     * the caller wrote only bounds where the request pointed: a symlink
     * moves where it landed, so the resolved target is admitted again
     * and must still lie under the same install root — `src/Link.php`
     * reaching `.env`, the package's own vendor tree or anything outside
     * the package is a read of something this tool does not serve,
     * however admitted its own name was.
     *
     * @param bool $listing whether this call may name the package root
     *        with {@see ROOT}: a listing has that directory to report,
     *        and a read has no file behind it.
     * @return array{version: string, root: string, target: string}|Document
     */
    private function resolve(string $package, string $path, bool $listing): array|Document
    {
        $source = $this->packages->source($package);

        if ($source === null) {
            return self::refuse('package_unknown');
        }

        $named = $listing && $path === self::ROOT;

        if (!$named && !self::admits($path)) {
            return self::refuse('path_not_admitted');
        }

        $root = realpath($source['root']);
        $target = $root === false ? false : realpath($root . '/' . $path);

        if ($target === false) {
            return self::refuse('source_missing');
        }

        // The root token resolves to the root, so only a path under it
        // has somewhere else it could have landed.
        if (!$named) {
            // The separator keeps a sibling directory whose name merely
            // starts with the root's out, and rejects a target that
            // resolved anywhere else.
            if (!str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
                return self::refuse('source_unreadable');
            }

            if (!self::admits(self::relative($root, $target))) {
                return self::refuse('path_not_admitted');
            }
        }

        return [
            'version' => $source['version'],
            'root' => $root,
            'target' => $target,
        ];
    }

    /**
     * The reportable children of one resolved directory, unordered, or
     * the refusal that stopped the scan.
     *
     * A name that is not UTF-8 refuses the listing rather than being
     * dropped or encoded: it cannot be reported as JSON, and a listing
     * quietly missing one entry is not the directory it claims to be.
     *
     * @param string $root the resolved install root every child must stay under
     * @return list<array{name: string, type: string}>|Document
     */
    private static function children(string $root, string $target): array|Document
    {
        // Suppressed for the reason the file open is: a directory that
        // cannot be opened is the returned code, not a warning on a
        // stdout that carries JSON-RPC frames.
        $handle = @opendir($target);

        if ($handle === false) {
            return self::refuse('source_unreadable');
        }

        /** @var list<array{name: string, type: string}> $entries */
        $entries = [];

        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                if (preg_match('//u', $name) !== 1) {
                    return self::refuse('source_unreadable');
                }

                $type = self::classify($root, $target . DIRECTORY_SEPARATOR . $name);

                if ($type === null) {
                    continue;
                }

                // Counted against what would be reported, and decided on
                // the child past the cap, so the refusal is the whole
                // answer and no partial name is built.
                if (count($entries) === self::MAX_ENTRY_COUNT) {
                    return self::refuse('directory_oversize');
                }

                $entries[] = ['name' => $name, 'type' => $type];
            }
        } finally {
            closedir($handle);
        }

        return $entries;
    }

    /**
     * `file` or `directory` for a child that is one and is admitted both
     * by its own name and by the target it resolves to, or null for a
     * child this tool does not serve.
     *
     * The link is resolved rather than asked about: a link and a file
     * are the same thing to the tools that would read what is listed, so
     * what matters is where the child lands and what is there — not how
     * it got there. The name is nonetheless decided on its own, because
     * a listing that named a hidden link to an ordinary file would offer
     * a name {@see read()} refuses.
     */
    private static function classify(string $root, string $child): ?string
    {
        if (!self::admits(self::relative($root, $child))) {
            return null;
        }

        $resolved = realpath($child);

        if ($resolved === false
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
            || !self::admits(self::relative($root, $resolved))) {
            return null;
        }

        return match (true) {
            is_dir($resolved) => 'directory',
            is_file($resolved) => 'file',
            default => null,
        };
    }

    /** One path under the install root as the relative path a call names it by. */
    private static function relative(string $root, string $target): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($target, strlen($root) + 1));
    }

    /**
     * Whether one relative path is one this tool serves, decided by its
     * syntax alone: the path a caller wrote, the path a symlink resolved
     * to, and every child a listing considers all pass through here.
     *
     * The install root is the boundary because an installed package puts
     * its production source where its own autoload map says — at the
     * package root for a root-mapped namespace, under `lib`, beside
     * generated and classmap files — so a fixed list of directories
     * would refuse the package's real evidence while proving nothing.
     * What is refused is what is not that package's own readable
     * content: an empty segment, which covers the empty path as well as
     * a leading, trailing or doubled separator; a segment opening with a
     * dot, which covers `.`, `..` and every hidden name such as `.git`
     * or `.env`; a backslash or a NUL anywhere; and a first segment of
     * `vendor`, which keeps a read inside the package the call named
     * rather than crossing into a dependency tree under its identity. A
     * `vendor` directory deeper down is the package's own content and is
     * served.
     */
    private static function admits(string $path): bool
    {
        if (str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }

        $segments = explode('/', $path);

        if ($segments[0] === 'vendor') {
            return false;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false;
            }
        }

        return true;
    }

    /**
     * A refusal carries the code and nothing else: a path, a root, a
     * Composer detail or a fragment of the file would each be a leak out
     * of a tool whose whole read set is meant to be unobservable.
     */
    private static function refuse(string $code): Document
    {
        return new Document(['status' => 'error', 'code' => $code], failed: true);
    }
}
