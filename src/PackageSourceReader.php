<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * One window, one bounded literal search of a file or of a directory
 * tree, or one directory listing, of one real installed, non-root
 * Composer package, as the JSON document the MCP tools return.
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
 * relative path, and the window or the query. Every call reaches the
 * filesystem through one resolver: the install root comes from
 * Composer's own installed set and is the whole read boundary; the path
 * is admitted by its syntax alone before anything is opened; and the
 * resolved target must satisfy that same syntax and still lie under
 * that same root. A symlink is therefore resolved and then re-admitted,
 * so one pointing out of the package, or at a name this tool does not
 * serve, is refused rather than followed — and a listing and a tree
 * search re-admit each child the same way, so they reach only what a
 * read of that name could reach. A refusal names a fixed code and
 * nothing else — no resolved path, no exception text, no content.
 *
 * Every bound is enforced by construction: a read is one stream read of
 * the admitted size plus one byte, and that byte alone tells an admitted
 * file from an oversized one; a listing stops at the child past the
 * admitted count and reports no partial directory; a tree search stops
 * its walk at the file or the byte past its budget, before any file is
 * opened, and reports no partial match list. Nothing is retained between
 * calls.
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
     * The most reportable regular files one tree search walks; the file
     * past it refuses the call.
     */
    public const int MAX_TREE_FILE_COUNT = 512;

    /**
     * The most bytes of searchable files one tree search reads; the file
     * that would pass it refuses the call.
     */
    public const int MAX_TREE_BYTES = 8388608;

    /**
     * The one path that names the package root itself. Only a call that
     * names a directory takes it: it is where a caller starts when the
     * layout is unknown, and there is no file behind it for a read to
     * open.
     */
    public const string ROOT = '.';

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

        foreach (self::matches($lines, $query, $startLine) as $line => $content) {
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
     * The lines of every file under one admitted directory that contain
     * $query, up to {@see MAX_MATCH_COUNT} of them in bytewise path order
     * and then line order, or the refusal. Finding none is a successful
     * search with an empty list, not a refusal.
     *
     * Each line is compared exactly as {@see search()} compares it. A
     * match's path is relative to the package root and spelled by the
     * names the walk took from the named path, a link's own name
     * included, so it is the path a window of that file takes.
     *
     * The walk admits every file and directory by the rule a listing
     * applies to its children, and it is bounded before any file is
     * opened: the tree is refused whole with `package_search_oversize`
     * when it holds more than {@see MAX_TREE_FILE_COUNT} reportable
     * files, or when the files it would read pass
     * {@see MAX_TREE_BYTES}. The bounds describe the tree, not the query,
     * so a narrower path is the only way past them. A file a window
     * would refuse as `source_oversize` or `source_not_text` is skipped
     * rather than refusing the search, so an unrelated asset beside the
     * source does not stop it; the oversized one is skipped unread and
     * does not count toward the bytes.
     *
     * Scanning stops at the first match past the cap. There is no cursor
     * to continue from: `hasMore` says the caller narrows the query or
     * the path.
     *
     * @param string $path relative `/` syntax naming a directory under
     *        the package root, or {@see ROOT} for the root itself
     * @param string $query non-empty, already validated by the adapter to
     *        at most {@see MAX_QUERY_LENGTH} characters
     */
    public function searchTree(string $package, string $path, string $query): Document
    {
        $resolved = $this->resolve($package, $path, directory: true);

        if ($resolved instanceof Document) {
            return $resolved;
        }

        if (!is_dir($resolved['target'])) {
            return self::refuse('source_not_directory');
        }

        $files = self::tree($resolved['root'], $resolved['target'], $path === self::ROOT ? '' : $path . '/');

        if ($files instanceof Document) {
            return $files;
        }

        /** @var list<array{path: string, line: int, content: string}> $matches */
        $matches = [];
        $hasMore = false;

        foreach ($files as [$logical, $file]) {
            $lines = self::lines($file);

            // Not text, or grown past the ceiling since the walk measured
            // it: skipped like the oversized file the walk left out. Any
            // other refusal is the answer.
            if ($lines === 'source_oversize' || $lines === 'source_not_text') {
                continue;
            }

            if (is_string($lines)) {
                return self::refuse($lines);
            }

            foreach (self::matches($lines, $query, 1) as $line => $content) {
                if (count($matches) === self::MAX_MATCH_COUNT) {
                    $hasMore = true;

                    break 2;
                }

                $matches[] = ['path' => $logical, 'line' => $line, 'content' => $content];
            }
        }

        return new Document([
            'status' => 'ok',
            'package' => $package,
            'version' => $resolved['version'],
            'path' => $path,
            'query' => $query,
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
        $resolved = $this->resolve($package, $path, directory: true);

        if ($resolved instanceof Document) {
            return $resolved;
        }

        // An admitted path behind a regular file is a call that named
        // the wrong kind of thing, not one that named something unserved.
        if (!is_dir($resolved['target'])) {
            return self::refuse('source_not_directory');
        }

        /** @var list<array{name: string, type: string}> $entries */
        $entries = [];
        $children = self::children($resolved['root'], $resolved['target']);

        foreach ($children as $name => [$type]) {
            // Counted against what would be reported, and decided on the
            // child past the cap, so the refusal is the whole answer and
            // no partial name is built.
            if (count($entries) === self::MAX_ENTRY_COUNT) {
                return self::refuse('directory_oversize');
            }

            $entries[] = ['name' => $name, 'type' => $type];
        }

        $refusal = $children->getReturn();

        if ($refusal !== null) {
            return $refusal;
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
     *
     * @return array{string, list<string>}|Document
     */
    private function load(string $package, string $path): array|Document
    {
        $resolved = $this->resolve($package, $path, directory: false);

        if ($resolved instanceof Document) {
            return $resolved;
        }

        $lines = self::lines($resolved['target']);

        return is_string($lines) ? self::refuse($lines) : [$resolved['version'], $lines];
    }

    /**
     * The lines of one resolved file, or the code that refuses it: the
     * file work a window, a file search and a tree search share, so each
     * classifies a file the same way.
     *
     * @return list<string>|string
     */
    private static function lines(string $target): array|string
    {
        // A directory or any other non-regular target is refused here
        // rather than opened.
        if (!is_file($target)) {
            return 'source_unreadable';
        }

        // Suppressed because the diagnostic is the returned code, not a
        // PHP warning on a stdout that carries JSON-RPC frames.
        $handle = @fopen($target, 'rb');

        if ($handle === false) {
            return 'source_unreadable';
        }

        $contents = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);

        fclose($handle);

        if ($contents === false) {
            return 'source_unreadable';
        }

        // The extra byte arrived, so the file is larger than the
        // admitted size. Nothing beyond it was ever read.
        if (strlen($contents) > self::MAX_SOURCE_BYTES) {
            return 'source_oversize';
        }

        // A NUL byte or an invalid encoding means this is not the source
        // text the tool reports; `//u` decides UTF-8 without mbstring.
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            return 'source_not_text';
        }

        // Split after each newline, so every line keeps its own ending,
        // CRLF included, and a file with no final newline keeps that.
        // A split point only ever follows a newline, so the sole empty
        // piece PREG_SPLIT_NO_EMPTY can drop is the one past a trailing
        // newline.
        $lines = preg_split('/(?<=\n)/', $contents, flags: PREG_SPLIT_NO_EMPTY);
        \assert(is_array($lines));

        return $lines;
    }

    /**
     * Each line from $startLine on that contains $query, keyed by its
     * one-based number and without its terminator.
     *
     * @param list<string> $lines
     * @return \Generator<int, string>
     */
    private static function matches(array $lines, string $query, int $startLine): \Generator
    {
        $total = count($lines);

        for ($line = $startLine; $line <= $total; $line++) {
            $content = $lines[$line - 1];

            // The terminator is the file's, not the line's, and a line
            // carries at most one because the split point follows it.
            if (str_ends_with($content, "\n")) {
                $content = substr($content, 0, str_ends_with($content, "\r\n") ? -2 : -1);
            }

            if (str_contains($content, $query)) {
                yield $line => $content;
            }
        }
    }

    /**
     * The install root, the resolved target and the version behind one
     * admitted path, or the refusal that stopped the call.
     *
     * Every rule that bounds what this package serves lives here, so a
     * read, a search and a listing are admitted and confined identically
     * and differ only in what they do with the target. Admitting the path
     * the caller wrote only bounds where the request pointed: a symlink
     * moves where it landed, so the resolved target is admitted again
     * and must still lie under the same install root — `src/Link.php`
     * reaching `.env`, the package's own vendor tree or anything outside
     * the package is a read of something this tool does not serve,
     * however admitted its own name was.
     *
     * @param bool $directory whether this call names a directory, and so
     *        may name the package root with {@see ROOT}: a listing and a
     *        tree search have that directory to work on, and a read has
     *        no file behind it.
     * @return array{version: string, root: string, target: string}|Document
     */
    private function resolve(string $package, string $path, bool $directory): array|Document
    {
        $source = $this->packages->source($package);

        if ($source === null) {
            return self::refuse('package_unknown');
        }

        $named = $directory && $path === self::ROOT;

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
     * The reportable children of one resolved directory, each yielded as
     * its name and its {@see classify()} type and resolved target in the
     * order the directory is read; the generator returns the refusal that
     * stopped the scan, or null once every child was considered.
     *
     * A generator, so a listing and a tree walk each stop at their own
     * bound without the whole directory being collected first. A name
     * that is not UTF-8 refuses the scan rather than being dropped or
     * encoded: it cannot be reported as JSON, and a listing or a search
     * quietly missing one entry is not the directory it claims to be.
     *
     * @param string $root the resolved install root every child must stay under
     * @return \Generator<string, array{string, string}, mixed, Document|null>
     */
    private static function children(string $root, string $target): \Generator
    {
        // Suppressed for the reason the file open is: a directory that
        // cannot be opened is the returned code, not a warning on a
        // stdout that carries JSON-RPC frames.
        $handle = @opendir($target);

        if ($handle === false) {
            return self::refuse('source_unreadable');
        }

        // The handle closes on every way out, a consumer that stops
        // early included: destroying a suspended generator runs this.
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                if (preg_match('//u', $name) !== 1) {
                    return self::refuse('source_unreadable');
                }

                $classified = self::classify($root, $target . DIRECTORY_SEPARATOR . $name);

                if ($classified !== null) {
                    yield $name => $classified;
                }
            }
        } finally {
            closedir($handle);
        }

        return null;
    }

    /**
     * Every file under one resolved directory that a tree search reads,
     * as the path it is reported by and the resolved target it is
     * measured and opened by, in bytewise reported-path order — or the
     * refusal that stopped the walk.
     *
     * Each level is admitted through {@see children()}, so the walk
     * reaches exactly what successive listings would. The reported path
     * is the named path followed by each child's own name, a link's
     * included, so it is the path a window of that file takes. Every
     * measurement, open and descent uses the target that child resolved
     * to when it was re-admitted, so retargeting a link after its
     * admission does not move what is read. A directory is walked once,
     * by its resolved target and under the first name the walk reaches
     * it by: a link back to an ancestor or to a directory already walked
     * cannot loop or repeat it. Subdirectories are walked in bytewise order, so
     * the same tree picks the same name on every platform.
     *
     * The two budgets are spent before any file is opened. Every
     * reportable regular file counts toward {@see MAX_TREE_FILE_COUNT};
     * a file past {@see MAX_SOURCE_BYTES} is the `source_oversize` a
     * window reports, so it is left out unread, and every other file's
     * size counts toward {@see MAX_TREE_BYTES}.
     *
     * @param string $target the resolved directory the call named
     * @param string $prefix the reported path of $target, empty for the
     *        root and otherwise ending in `/`
     * @return list<array{string, string}>|Document
     */
    private static function tree(string $root, string $target, string $prefix): array|Document
    {
        /** @var list<array{string, string}> $files */
        $files = [];
        $count = 0;
        $bytes = 0;
        /** @var array<string, true> $walked */
        $walked = [];
        /** @var list<array{string, string}> $pending */
        $pending = [[$prefix, $target]];

        while (($next = array_pop($pending)) !== null) {
            [$prefix, $directory] = $next;

            if (isset($walked[$directory])) {
                continue;
            }

            $walked[$directory] = true;

            /** @var list<array{string, string}> $subdirectories */
            $subdirectories = [];
            $children = self::children($root, $directory);

            foreach ($children as $name => [$type, $resolved]) {
                if ($type === 'directory') {
                    $subdirectories[] = [$prefix . $name . '/', $resolved];

                    continue;
                }

                if (++$count > self::MAX_TREE_FILE_COUNT) {
                    return self::refuse('package_search_oversize');
                }

                // The process outlives a package update, so the size is
                // this file's now, not one PHP cached on an earlier call.
                clearstatcache(true, $resolved);

                // Suppressed for the reason the file open is.
                $size = @filesize($resolved);

                if ($size === false) {
                    return self::refuse('source_unreadable');
                }

                if ($size > self::MAX_SOURCE_BYTES) {
                    continue;
                }

                $bytes += $size;

                if ($bytes > self::MAX_TREE_BYTES) {
                    return self::refuse('package_search_oversize');
                }

                $files[] = [$prefix . $name, $resolved];
            }

            $refusal = $children->getReturn();

            if ($refusal !== null) {
                return $refusal;
            }

            // Pushed in descending order, so the stack pops them ascending.
            usort($subdirectories, static fn (array $first, array $second): int => strcmp($second[0], $first[0]));
            array_push($pending, ...$subdirectories);
        }

        usort($files, static fn (array $first, array $second): int => strcmp($first[0], $second[0]));

        return $files;
    }

    /**
     * `file` or `directory` and the resolved target for a child that is
     * one and is admitted both by its own name and by that target, or
     * null for a child this tool does not serve.
     *
     * The link is resolved rather than asked about: a link and a file
     * are the same thing to the tools that would read what is listed, so
     * what matters is where the child lands and what is there — not how
     * it got there. The name is nonetheless decided on its own, because
     * a listing that named a hidden link to an ordinary file would offer
     * a name {@see read()} refuses.
     *
     * @return array{string, string}|null
     */
    private static function classify(string $root, string $child): ?array
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

        $type = match (true) {
            is_dir($resolved) => 'directory',
            is_file($resolved) => 'file',
            default => null,
        };

        return $type === null ? null : [$type, $resolved];
    }

    /** One path under the install root as the relative path a call names it by. */
    private static function relative(string $root, string $target): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($target, strlen($root) + 1));
    }

    /**
     * Whether one relative path is one this tool serves, decided by its
     * syntax alone: the path a caller wrote, the path a symlink resolved
     * to, and every child a listing or a tree search considers all pass
     * through here.
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
