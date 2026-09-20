<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use JsonException;
use Kinetis\Orbitron\Document;
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use Kinetis\Orbitron\PackageSourceReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The installed-source window and the literal search over the same
 * files, against a real package directory: the bytes a window carries,
 * the lines a search reports, and every refusal, decided from files on
 * disk rather than from a mocked filesystem.
 *
 * The package under test here is a fixture named like an installed one,
 * so what is proved is the reader's own rules — not whatever happens to
 * be installed beside the suite.
 */
final class PackageSourceReaderTest extends TestCase
{
    private const string PACKAGE = 'kinetis/fixture';

    private string $root;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/orbitron-source-' . bin2hex(random_bytes(8));

        if (!mkdir($root . '/src', 0o700, true)) {
            throw new RuntimeException("Could not create the fixture root {$root}.");
        }

        $this->root = $root;
    }

    protected function tearDown(): void
    {
        self::delete($this->root);
    }

    /** Removes the fixture without following a link out of it. */
    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::delete($path . '/' . $entry);
        }

        rmdir($path);
    }

    /**
     * The window is a byte range of the file, not a reformatting of it:
     * the two lines asked for arrive with their own newlines and nothing
     * else, and the report says where they came from.
     *
     * @throws JsonException
     */
    public function test_a_middle_window_carries_exactly_the_selected_lines(): void
    {
        $this->write('src/A.php', "one\ntwo\nthree\nfour\nfive\n");

        $document = $this->read('src/A.php', 2, 2);

        self::assertFalse($document->failed);
        self::assertSame(
            ['status', 'package', 'version', 'path', 'startLine', 'endLine', 'hasMore', 'content'],
            array_keys($document->body),
        );
        self::assertSame([
            'status' => 'ok',
            'package' => self::PACKAGE,
            'version' => '2.4.0',
            'path' => 'src/A.php',
            'startLine' => 2,
            'endLine' => 3,
            'hasMore' => true,
            'content' => "two\nthree\n",
        ], $document->body);
    }

    /**
     * The last window stops at the last line however many were asked
     * for, says there is no more, and keeps a missing final newline
     * missing — a file whose end was invented would read as a different
     * file.
     */
    public function test_a_final_window_stops_at_the_last_line_and_keeps_a_missing_final_newline(): void
    {
        $this->write('src/A.php', "one\ntwo\nthree");

        $document = $this->read('src/A.php', 3, 200);

        self::assertSame(3, $document->body['startLine']);
        self::assertSame(3, $document->body['endLine']);
        self::assertFalse($document->body['hasMore']);
        self::assertSame('three', $document->body['content']);
    }

    /**
     * A CRLF file is reported as it is stored. Splitting after the
     * newline leaves the carriage return on the line it belongs to, so
     * the returned bytes are the file's own.
     */
    public function test_windows_line_endings_survive_the_window(): void
    {
        $this->write('src/A.php', "one\r\ntwo\r\nthree\r\n");

        self::assertSame("two\r\n", $this->read('src/A.php', 2, 1)->body['content']);
    }

    public function test_a_window_wider_than_the_file_returns_the_whole_file(): void
    {
        $this->write('src/A.php', "one\ntwo\n");

        $document = $this->read('src/A.php', 1, 200);

        self::assertSame("one\ntwo\n", $document->body['content']);
        self::assertSame(2, $document->body['endLine']);
        self::assertFalse($document->body['hasMore']);
    }

    /**
     * The three other admitted locations, each reached by name.
     *
     * @return iterable<string, array{string}>
     */
    public static function admittedPathProvider(): iterable
    {
        yield 'package manifest' => ['composer.json'];
        yield 'readme' => ['README.md'];
        yield 'nested source' => ['src/Http/Controller.php'];
    }

    #[DataProvider('admittedPathProvider')]
    public function test_every_admitted_location_can_be_read(string $path): void
    {
        $this->write($path, "body\n");

        self::assertSame("body\n", $this->read($path, 1, 200)->body['content']);
    }

    /**
     * Nothing is held between calls: the same reader answers from the
     * file as it is now, so an edit made after one read is what the next
     * one returns.
     */
    public function test_a_rewritten_file_is_re_read_rather_than_answered_from_a_kept_window(): void
    {
        $this->write('src/A.php', "before\n");

        $reader = $this->reader();

        self::assertSame("before\n", $reader->read(self::PACKAGE, 'src/A.php', 1, 200)->body['content']);

        $this->write('src/A.php', "after\nand more\n");

        $second = $reader->read(self::PACKAGE, 'src/A.php', 1, 200);

        self::assertSame("after\nand more\n", $second->body['content']);
        self::assertSame(2, $second->body['endLine']);
    }

    public function test_an_uninstalled_package_is_refused_without_a_lookup_of_the_path(): void
    {
        self::assertRefusal('package_unknown', $this->reader()->read('kinetis/absent', 'src/A.php', 1, 200));
    }

    /**
     * The Composer root project is the checkout being developed, not an
     * installed package, so its source is not readable through this
     * tool either.
     */
    public function test_the_composer_root_project_is_not_a_readable_package(): void
    {
        $this->write('src/A.php', "one\n");

        $reader = new PackageSourceReader(new InstalledPackages([
            new PackageFact(self::PACKAGE, '2.4.0', $this->root, root: true),
        ]));

        self::assertRefusal('package_unknown', $reader->read(self::PACKAGE, 'src/A.php', 1, 200));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inadmissiblePathProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc/passwd'];
        yield 'leading slash on an admitted directory' => ['/src/A.php'];
        yield 'parent segment' => ['src/../../secret'];
        yield 'trailing parent segment' => ['src/..'];
        yield 'current segment' => ['src/./A.php'];
        yield 'doubled separator' => ['src//A.php'];
        yield 'trailing separator' => ['src/'];
        yield 'backslash separator' => ['src\\A.php'];
        yield 'NUL byte' => ["src/A.php\0.txt"];
        yield 'unadmitted root file' => ['phpunit.xml'];
        yield 'unadmitted directory' => ['tests/ATest.php'];
        yield 'directory prefix only' => ['srcx/A.php'];
        yield 'bare admitted directory' => ['src'];
    }

    #[DataProvider('inadmissiblePathProvider')]
    public function test_a_path_outside_the_admitted_syntax_or_locations_is_refused(string $path): void
    {
        self::assertRefusal('path_not_admitted', $this->read($path, 1, 200));
    }

    public function test_an_admitted_path_with_no_file_behind_it_is_missing(): void
    {
        self::assertRefusal('source_missing', $this->read('src/Absent.php', 1, 200));
    }

    public function test_an_install_root_that_is_not_on_disk_is_missing(): void
    {
        $reader = new PackageSourceReader(new InstalledPackages([
            new PackageFact(self::PACKAGE, '2.4.0', $this->root . '/gone'),
        ]));

        self::assertRefusal('source_missing', $reader->read(self::PACKAGE, 'composer.json', 1, 200));
    }

    public function test_a_directory_is_not_a_readable_source_file(): void
    {
        $this->write('src/Http/Controller.php', "body\n");

        self::assertRefusal('source_unreadable', $this->read('src/Http', 1, 200));
    }

    /**
     * The boundary check is what makes the admitted syntax enough: a
     * symlink is a path the caller never wrote, and following one out of
     * the package would read a file this tool does not serve.
     */
    public function test_a_symlink_leaving_the_package_is_refused_rather_than_followed(): void
    {
        $outside = sys_get_temp_dir() . '/orbitron-outside-' . bin2hex(random_bytes(8));
        file_put_contents($outside, "secret\n");

        symlink($outside, $this->root . '/src/Escape.php');

        $document = $this->read('src/Escape.php', 1, 200);

        unlink($outside);

        self::assertRefusal('source_unreadable', $document);
    }

    /**
     * A symlink resolving inside the same admitted location is an
     * ordinary file: the refusals around it are about where the target
     * landed, not about links.
     */
    public function test_a_symlink_staying_in_the_same_admitted_location_is_read(): void
    {
        $this->write('src/A.php', "inside\n");
        $this->write('src/Http/Controller.php', "nested\n");

        $this->link('src/B.php', 'src/A.php');
        $this->link('src/Http/Alias.php', 'src/Http/Controller.php');
        $this->link('src/C.php', 'src/Http/Controller.php');

        self::assertSame("inside\n", $this->read('src/B.php', 1, 200)->body['content']);
        self::assertSame("nested\n", $this->read('src/Http/Alias.php', 1, 200)->body['content']);
        self::assertSame("nested\n", $this->read('src/C.php', 1, 200)->body['content']);
    }

    /**
     * Where an admitted path may point, and where a symlink under one
     * may not: the request's admitted location is the boundary, so a
     * link out of it reads something this tool does not serve however
     * admitted its own name was.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function escapingLinkProvider(): iterable
    {
        yield 'a source path reaching the readme' => ['src/Link.php', 'README.md'];
        yield 'a source path reaching the manifest' => ['src/Link.php', 'composer.json'];
        yield 'a source path reaching the test suite' => ['src/Link.php', 'tests/SecretTest.php'];
        yield 'a source path reaching the vendor tree' => ['src/Link.php', 'vendor/private/Secret.php'];
        yield 'a source path reaching an unadmitted root file' => ['src/Link.php', 'phpunit.xml'];
        yield 'a readme reaching the source tree' => ['README.md', 'src/A.php'];
        yield 'a binary reaching the source tree' => ['bin/tool', 'src/A.php'];
    }

    /**
     * @param string $path the admitted path the call names
     * @param string $target where the link behind it resolves, inside the package
     */
    #[DataProvider('escapingLinkProvider')]
    public function test_a_symlink_leaving_its_admitted_location_is_refused(string $path, string $target): void
    {
        $this->write($target, "secret body\n");
        $this->write('src/A.php', "source\n");

        if (is_file($this->root . '/' . $path)) {
            unlink($this->root . '/' . $path);
        }

        $this->link($path, $target);

        $document = $this->read($path, 1, 200);

        self::assertRefusal('path_not_admitted', $document);
        self::assertStringNotContainsString('secret body', $document->toJson());
    }

    public function test_a_file_past_the_admitted_size_is_refused_before_it_is_read_whole(): void
    {
        $this->write('src/A.php', str_repeat('a', PackageSourceReader::MAX_SOURCE_BYTES + 1));

        self::assertRefusal('source_oversize', $this->read('src/A.php', 1, 200));
    }

    public function test_a_file_at_exactly_the_admitted_size_is_read(): void
    {
        $this->write('src/A.php', str_repeat('a', PackageSourceReader::MAX_SOURCE_BYTES));

        self::assertSame(
            PackageSourceReader::MAX_SOURCE_BYTES,
            strlen($this->read('src/A.php', 1, 200)->body['content']),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonTextProvider(): iterable
    {
        yield 'NUL byte' => ["<?php\n\0\n"];
        yield 'invalid UTF-8' => ["<?php\n\xC3\x28\n"];
        yield 'truncated multi-byte sequence' => ["<?php\n\xE2\x82\n"];
    }

    #[DataProvider('nonTextProvider')]
    public function test_content_that_is_not_utf8_text_is_refused(string $contents): void
    {
        $this->write('src/A.php', $contents);

        self::assertRefusal('source_not_text', $this->read('src/A.php', 1, 200));
    }

    public function test_valid_multi_byte_text_is_read_unchanged(): void
    {
        $this->write('src/A.php', "// ☑ écrit\n");

        self::assertSame("// ☑ écrit\n", $this->read('src/A.php', 1, 200)->body['content']);
    }

    public function test_a_start_line_past_the_last_line_is_refused(): void
    {
        $this->write('src/A.php', "one\ntwo\n");

        self::assertRefusal('line_out_of_range', $this->read('src/A.php', 3, 200));
    }

    public function test_an_empty_file_has_no_line_to_start_at(): void
    {
        $this->write('src/A.php', '');

        self::assertRefusal('line_out_of_range', $this->read('src/A.php', 1, 200));
    }

    /**
     * Every refusal is the code alone. A document that named the root it
     * resolved, the file it looked for or the bytes it read would hand
     * back exactly what the tool exists not to expose.
     *
     * @throws JsonException
     */
    public function test_no_refusal_carries_a_path_a_root_or_any_content(): void
    {
        $this->write('src/Http/Controller.php', "secret body\n");
        $this->write('src/Big.php', str_repeat('a', PackageSourceReader::MAX_SOURCE_BYTES + 1));
        $this->write('src/Binary.php', "\0");

        $refusals = [
            'package_unknown' => $this->reader()->read('kinetis/absent', 'src/A.php', 1, 200),
            'path_not_admitted' => $this->read('../../etc/passwd', 1, 200),
            'source_missing' => $this->read('src/Absent.php', 1, 200),
            'source_unreadable' => $this->read('src/Http', 1, 200),
            'source_oversize' => $this->read('src/Big.php', 1, 200),
            'source_not_text' => $this->read('src/Binary.php', 1, 200),
            'line_out_of_range' => $this->read('src/Http/Controller.php', 9, 200),
        ];

        foreach ($refusals as $code => $document) {
            self::assertRefusal((string) $code, $document);

            $json = $document->toJson();

            self::assertSame(['status', 'code'], array_keys($document->body));
            self::assertStringNotContainsString($this->root, $json);
            self::assertStringNotContainsString(sys_get_temp_dir(), $json);
            self::assertStringNotContainsString('secret body', $json);
            self::assertStringNotContainsString('vendor', $json);
        }
    }

    /**
     * The match list is the lines of the file that contain the literal,
     * in source order, each at its own line number and without the
     * terminator the file stores.
     *
     * @throws JsonException
     */
    public function test_a_search_reports_every_matching_line_in_source_order(): void
    {
        $this->write('src/A.php', "public function read(): void\n{\n    // read the file\n}\nreturn 0;\n");

        $document = $this->search('src/A.php', 'read', 1);

        self::assertFalse($document->failed);
        self::assertSame(
            ['status', 'package', 'version', 'path', 'query', 'startLine', 'matches', 'hasMore'],
            array_keys($document->body),
        );
        self::assertSame([
            'status' => 'ok',
            'package' => self::PACKAGE,
            'version' => '2.4.0',
            'path' => 'src/A.php',
            'query' => 'read',
            'startLine' => 1,
            'matches' => [
                ['line' => 1, 'content' => 'public function read(): void'],
                ['line' => 3, 'content' => '    // read the file'],
            ],
            'hasMore' => false,
        ], $document->body);
    }

    /**
     * The scan is literal and case-sensitive: a symbol is found by the
     * spelling it has, and a differently-cased line is not a match.
     */
    public function test_the_scan_is_case_sensitive(): void
    {
        $this->write('src/A.php', "read the file\nRead the file\n");

        self::assertSame(
            [['line' => 2, 'content' => 'Read the file']],
            $this->search('src/A.php', 'Read', 1)->body['matches'],
        );
    }

    /**
     * A CRLF file reports the line without either terminator byte: a
     * carriage return left on the end would be part of every match a
     * caller compares or prints.
     */
    public function test_a_crlf_line_is_reported_without_its_terminator(): void
    {
        $this->write('src/A.php', "one\r\ntwo\r\nthree\r\n");

        self::assertSame(
            [['line' => 2, 'content' => 'two']],
            $this->search('src/A.php', 'two', 1)->body['matches'],
        );
    }

    /**
     * Exactly one terminator is removed. A carriage return the file
     * stores before its line ending is part of the line, and a trim of
     * every trailing one would silently change the text reported.
     */
    public function test_only_one_line_terminator_is_removed_from_a_reported_line(): void
    {
        $this->write('src/A.php', "one\nkeep\r\r\n");

        self::assertSame(
            [['line' => 2, 'content' => "keep\r"]],
            $this->search('src/A.php', 'keep', 1)->body['matches'],
        );
    }

    /**
     * The query is matched by its characters, not by bytes the file
     * happens to store, so a multi-byte literal finds its line and the
     * line comes back unchanged.
     */
    public function test_a_multi_byte_query_matches_and_is_echoed_with_the_line(): void
    {
        $this->write('src/A.php', "// plain\n// ☑ écrit\n");

        $document = $this->search('src/A.php', '☑ écrit', 1);

        self::assertSame([['line' => 2, 'content' => '// ☑ écrit']], $document->body['matches']);
        self::assertSame('☑ écrit', $document->body['query']);
    }

    /** A last line with no terminator at all is scanned like any other. */
    public function test_a_match_on_a_last_line_without_a_final_newline_is_reported(): void
    {
        $this->write('src/A.php', "one\ntwo\nthree");

        self::assertSame(
            [['line' => 3, 'content' => 'three']],
            $this->search('src/A.php', 'three', 1)->body['matches'],
        );
    }

    /**
     * Finding nothing is an answer, not a refusal: the file was read and
     * the literal is not in it.
     */
    public function test_a_query_that_matches_nothing_is_a_successful_empty_search(): void
    {
        $this->write('src/A.php', "one\ntwo\n");

        $document = $this->search('src/A.php', 'three', 1);

        self::assertFalse($document->failed);
        self::assertSame([], $document->body['matches']);
        self::assertFalse($document->body['hasMore']);
    }

    /**
     * A query carrying a line ending matches nothing, because each line
     * is compared as it is reported. Nothing about it is refused: the
     * search simply has no line to find it on.
     */
    public function test_a_query_containing_a_line_terminator_matches_nothing(): void
    {
        $this->write('src/A.php', "one\ntwo\n");

        $document = $this->search('src/A.php', "one\ntwo", 1);

        self::assertFalse($document->failed);
        self::assertSame([], $document->body['matches']);
    }

    /** The scan starts where it is told to, so an earlier match is not reported again. */
    public function test_a_later_start_line_skips_the_matches_before_it(): void
    {
        $this->write('src/A.php', "hit\nmiss\nhit\n");

        $document = $this->search('src/A.php', 'hit', 2);

        self::assertSame(2, $document->body['startLine']);
        self::assertSame([['line' => 3, 'content' => 'hit']], $document->body['matches']);
    }

    /**
     * The cap is the reported maximum, and `hasMore` is decided by one
     * match past it: continuing from the last reported line plus one
     * returns the rest, with nothing repeated and nothing skipped.
     */
    public function test_matches_past_the_cap_continue_from_the_last_reported_line(): void
    {
        $total = PackageSourceReader::MAX_MATCH_COUNT + 10;
        $this->write('src/A.php', str_repeat("hit\n", $total));

        $first = $this->search('src/A.php', 'hit', 1);

        self::assertCount(PackageSourceReader::MAX_MATCH_COUNT, $first->body['matches']);
        self::assertTrue($first->body['hasMore']);

        $last = $first->body['matches'][PackageSourceReader::MAX_MATCH_COUNT - 1]['line'];
        self::assertSame(PackageSourceReader::MAX_MATCH_COUNT, $last);

        $second = $this->search('src/A.php', 'hit', $last + 1);

        self::assertCount(10, $second->body['matches']);
        self::assertFalse($second->body['hasMore']);
        self::assertSame(
            range(1, $total),
            array_column([...$first->body['matches'], ...$second->body['matches']], 'line'),
        );
    }

    /**
     * Exactly the cap and no more is a complete answer: `hasMore` says a
     * later match exists, not that the cap was reached.
     */
    public function test_exactly_the_cap_is_reported_as_complete(): void
    {
        $this->write(
            'src/A.php',
            str_repeat("hit\n", PackageSourceReader::MAX_MATCH_COUNT) . str_repeat("miss\n", 5),
        );

        $document = $this->search('src/A.php', 'hit', 1);

        self::assertCount(PackageSourceReader::MAX_MATCH_COUNT, $document->body['matches']);
        self::assertFalse($document->body['hasMore']);
    }

    public function test_a_search_start_line_past_the_last_line_is_refused(): void
    {
        $this->write('src/A.php', "one\ntwo\n");

        self::assertRefusal('line_out_of_range', $this->search('src/A.php', 'one', 3));
    }

    public function test_an_empty_file_has_no_line_to_search_from(): void
    {
        $this->write('src/A.php', '');

        self::assertRefusal('line_out_of_range', $this->search('src/A.php', 'one', 1));
    }

    /**
     * Nothing is held between calls here either: the same reader
     * searches the file as it is now.
     */
    public function test_a_search_re_reads_a_rewritten_file(): void
    {
        $this->write('src/A.php', "before\n");

        $reader = $this->reader();

        self::assertSame([], $reader->search(self::PACKAGE, 'src/A.php', 'after', 1)->body['matches']);

        $this->write('src/A.php', "before\nafter\n");

        self::assertSame(
            [['line' => 2, 'content' => 'after']],
            $reader->search(self::PACKAGE, 'src/A.php', 'after', 1)->body['matches'],
        );
    }

    /**
     * The search reaches a file through the same admission, confinement
     * and bounds the window does, so every refusal is the read's own —
     * the code alone, with no path, root or content behind it.
     *
     * @throws JsonException
     */
    public function test_every_refusal_reaches_a_search_as_the_code_alone(): void
    {
        $this->write('src/Http/Controller.php', "secret body\n");
        $this->write('src/Big.php', str_repeat('a', PackageSourceReader::MAX_SOURCE_BYTES + 1));
        $this->write('src/Binary.php', "\0");
        $this->link('src/Link.php', 'README.md');
        $this->write('README.md', "secret body\n");

        $refusals = [
            'package_unknown' => $this->reader()->search('kinetis/absent', 'src/A.php', 'secret', 1),
            'path_not_admitted' => $this->search('tests/ATest.php', 'secret', 1),
            'source_missing' => $this->search('src/Absent.php', 'secret', 1),
            'source_unreadable' => $this->search('src/Http', 'secret', 1),
            'source_oversize' => $this->search('src/Big.php', 'a', 1),
            'source_not_text' => $this->search('src/Binary.php', 'secret', 1),
            'line_out_of_range' => $this->search('src/Http/Controller.php', 'secret', 9),
        ];

        foreach ($refusals as $code => $document) {
            self::assertRefusal((string) $code, $document);

            $json = $document->toJson();

            self::assertStringNotContainsString($this->root, $json);
            self::assertStringNotContainsString(sys_get_temp_dir(), $json);
            self::assertStringNotContainsString('secret body', $json);
        }

        // A symlink out of its admitted location is refused on the
        // search path too, and the file it reached is not searched.
        $escaped = $this->search('src/Link.php', 'secret', 1);

        self::assertRefusal('path_not_admitted', $escaped);
        self::assertStringNotContainsString('secret body', $escaped->toJson());
    }

    private static function assertRefusal(string $code, Document $document): void
    {
        self::assertTrue($document->failed, "expected a refusal carrying {$code}");
        self::assertSame(['status' => 'error', 'code' => $code], $document->body);
    }

    private function read(string $path, int $startLine, int $lineCount): Document
    {
        return $this->reader()->read(self::PACKAGE, $path, $startLine, $lineCount);
    }

    private function search(string $path, string $query, int $startLine): Document
    {
        return $this->reader()->search(self::PACKAGE, $path, $query, $startLine);
    }

    private function reader(): PackageSourceReader
    {
        return new PackageSourceReader(new InstalledPackages([
            new PackageFact(self::PACKAGE, '2.4.0', $this->root),
        ]));
    }

    /**
     * A symlink at $relative pointing at $target, both inside the
     * package — the case a lexical check alone would let through.
     */
    private function link(string $relative, string $target): void
    {
        $path = $this->root . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        symlink($this->root . '/' . $target, $path);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        file_put_contents($path, $contents);
    }
}
