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
 * Composer's own installed set; the path is admitted against the fixed
 * locations the call serves before anything is opened; and the resolved
 * target must still sit in the admitted location the request named. A
 * symlink is therefore resolved and then re-admitted, so one pointing
 * out of the package, or at a part of it this tool does not serve, is
 * refused rather than followed — and a listing re-admits each child the
 * same way, so it reports only what a read of that name could reach. A
 * refusal names a fixed code and nothing else — no resolved path, no
 * exception text, no content.
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

    /** The locations a file read serves: the two package-root files and the three directories. */
    private const array FILE_LOCATIONS = ['composer.json', 'README.md', 'src', 'bin', 'resources'];

    /** The locations a listing serves. A package root and a root file are not listable. */
    private const array DIRECTORY_LOCATIONS = ['src', 'bin', 'resources'];

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
     * A child is reported only when its resolved target is a regular
     * file or a directory still inside the same admitted location, which
     * is the rule {@see read()} applies to the path it is given: a link
     * out of the package, a link to a part of it this tool does not
     * serve, a dangling one, and a socket, device or fifo are left out
     * rather than offered as something to read next.
     *
     * The count is the bound. A directory whose reportable children pass
     * {@see MAX_ENTRY_COUNT} refuses whole, because a prefix of a
     * directory silently answers "what is in here" with something else.
     *
     * @param string $path relative `/` syntax naming `src`, `bin` or
     *        `resources`, or a directory beneath one
     */
    public function list(string $package, string $path): Document
    {
        $resolved = $this->resolve($package, $path, self::DIRECTORY_LOCATIONS);

        if ($resolved instanceof Document) {
            return $resolved;
        }

        // An admitted path behind a regular file is a call that named
        // the wrong kind of thing, not one that named something unserved.
        if (!is_dir($resolved['target'])) {
            return self::refuse('source_not_directory');
        }

        $entries = self::children($resolved['root'], $resolved['location'], $resolved['target']);

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
        $resolved = $this->resolve($package, $path, self::FILE_LOCATIONS);

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
     * and must sit in the same location — `src/Link.php` reaching the
     * README, the test suite or the vendor tree is a read of something
     * this tool does not serve, however admitted its own name was.
     *
     * @param list<string> $admitted the locations the calling operation serves
     * @return array{version: string, root: string, location: string, target: string}|Document
     */
    private function resolve(string $package, string $path, array $admitted): array|Document
    {
        $source = $this->packages->source($package);

        if ($source === null) {
            return self::refuse('package_unknown');
        }

        $location = self::location($path);

        if ($location === null || !in_array($location, $admitted, true)) {
            return self::refuse('path_not_admitted');
        }

        $root = realpath($source['root']);
        $target = $root === false ? false : realpath($root . '/' . $path);

        if ($target === false) {
            return self::refuse('source_missing');
        }

        // The separator keeps a sibling directory whose name merely
        // starts with the root's out, and rejects a target that resolved
        // anywhere else.
        if (!str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
            return self::refuse('source_unreadable');
        }

        if (self::location(self::relative($root, $target)) !== $location) {
            return self::refuse('path_not_admitted');
        }

        return [
            'version' => $source['version'],
            'root' => $root,
            'location' => $location,
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
     * @param string $location the admitted location every child must resolve back into
     * @return list<array{name: string, type: string}>|Document
     */
    private static function children(string $root, string $location, string $target): array|Document
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

                $type = self::classify($root, $location, $target . DIRECTORY_SEPARATOR . $name);

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
     * `file` or `directory` for a child whose resolved target is one and
     * still sits in the admitted location, or null for a child this tool
     * does not serve.
     *
     * The link is resolved rather than asked about: a link and a file
     * are the same thing to the tools that would read what is listed, so
     * what matters is where the child lands and what is there — not how
     * it got there.
     */
    private static function classify(string $root, string $location, string $child): ?string
    {
        $resolved = realpath($child);

        if ($resolved === false
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
            || self::location(self::relative($root, $resolved)) !== $location) {
            return null;
        }

        return match (true) {
            is_dir($resolved) => 'directory',
            is_file($resolved) => 'file',
            default => null,
        };
    }

    /** One resolved target as the relative path a location is read from. */
    private static function relative(string $root, string $target): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($target, strlen($root) + 1));
    }

    /**
     * The location a path names — the root file itself, or the top
     * directory everything below it lies in — or null when the path is
     * outside the syntax this tool admits. Whether that location is one
     * the call serves is {@see resolve()}'s decision, because a read and
     * a listing do not serve the same set.
     *
     * An empty segment covers a leading or trailing slash and a doubled
     * one, so no separator form reaches the filesystem; `.` and `..` are
     * refused outright rather than resolved and then checked.
     */
    private static function location(string $path): ?string
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0")) {
            return null;
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $segments[0];
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
