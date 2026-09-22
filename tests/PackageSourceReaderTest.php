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
 * The installed-source window, the literal search over the same files
 * and the directory listing that finds them, against a real package
 * directory: the bytes a window carries, the lines a search reports,
 * the children a listing names, and every refusal, decided from files
 * on disk rather than from a mocked filesystem.
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
        // Anything that is not a directory is unlinked, a link to one
        // and a special file included; only a real directory is walked.
        if (is_link($path) || !is_dir($path)) {
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
     * The layouts a real installed package keeps its own content in. The
     * install root is the boundary, so a root-mapped class beside the
     * manifest, a `lib` tree, a generated file, a classmap directory,
     * the package's own tests and a bundled `vendor` directory that is
     * not the first segment are all its evidence and all readable.
     *
     * @return iterable<string, array{string}>
     */
    public static function admittedPathProvider(): iterable
    {
        yield 'package manifest' => ['composer.json'];
        yield 'readme' => ['README.md'];
        yield 'nested source' => ['src/Http/Controller.php'];
        yield 'a root-mapped class beside the manifest' => ['AmpHttpClient.php'];
        yield 'a lib tree' => ['lib/Client.php'];
        yield 'a generated file' => ['generated/Metadata.php'];
        yield 'a classmap directory' => ['classes/Legacy.php'];
        yield 'the package\'s own tests' => ['tests/ClientTest.php'];
        yield 'an ordinary root file that is not one of the two' => ['phpunit.xml'];
        yield 'a vendor directory that is not the first segment' => ['src/vendor/Bundled.php'];
    }

    #[DataProvider('admittedPathProvider')]
    public function test_every_layout_a_package_keeps_its_content_in_can_be_read(string $path): void
    {
        $this->write($path, "body\n");

        self::assertSame("body\n", $this->read($path, 1, 200)->body['content']);
    }

    /**
     * The gap a fixed location list left. `symfony/http-client` maps its
     * namespace to `""`, so the installed production class the evidence
     * is in sits at the package root — the exact shape that was
     * unreadable. A window and a search both reach it now.
     */
    public function test_a_root_mapped_production_class_is_read_and_searched(): void
    {
        $this->write('composer.json', '{"autoload":{"psr-4":{"Symfony\\\\Component\\\\HttpClient\\\\":""}}}' . "\n");
        $this->write('AmpHttpClient.php', "<?php\n\nfinal class AmpHttpClient\n{\n}\n");

        self::assertSame(
            "<?php\n\nfinal class AmpHttpClient\n{\n}\n",
            $this->read('AmpHttpClient.php', 1, 200)->body['content'],
        );
        self::assertSame(
            [['line' => 3, 'content' => 'final class AmpHttpClient']],
            $this->search('AmpHttpClient.php', 'final class', 1)->body['matches'],
        );
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
     * The vendor a package belongs to is not what admits it, and it
     * decides nothing else either. An exact installed dependency
     * outside `kinetis/` — the source a Kinetis behavior can turn on —
     * is read under the same install root, refused outside it, and
     * reported in the same shape.
     */
    public function test_a_package_outside_the_kinetis_vendor_is_read_under_the_same_root(): void
    {
        $this->write('src/A.php', "one\n");
        $this->write('tests/ATest.php', "two\n");
        $this->write('vendor/private/Secret.php', "three\n");

        $reader = new PackageSourceReader(new InstalledPackages([
            new PackageFact('thesis/amqp', '0.9.1', $this->root),
        ]));

        $document = $reader->read('thesis/amqp', 'src/A.php', 1, 200);

        self::assertFalse($document->failed);
        self::assertSame('thesis/amqp', $document->body['package']);
        self::assertSame('0.9.1', $document->body['version']);
        self::assertSame("one\n", $document->body['content']);

        self::assertSame("two\n", $reader->read('thesis/amqp', 'tests/ATest.php', 1, 200)->body['content']);
        self::assertRefusal(
            'path_not_admitted',
            $reader->read('thesis/amqp', 'vendor/private/Secret.php', 1, 200),
        );
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
        yield 'leading slash on an ordinary directory' => ['/src/A.php'];
        yield 'parent segment' => ['src/../../secret'];
        yield 'trailing parent segment' => ['src/..'];
        yield 'current segment' => ['src/./A.php'];
        yield 'the root token, which only a listing takes' => ['.'];
        yield 'doubled separator' => ['src//A.php'];
        yield 'trailing separator' => ['src/'];
        yield 'backslash separator' => ['src\\A.php'];
        yield 'NUL byte' => ["src/A.php\0.txt"];
        yield 'hidden root file' => ['.env'];
        yield 'file under a hidden root directory' => ['.git/config'];
        yield 'nested hidden directory' => ['src/.hidden/Secret.php'];
        yield 'hidden file in an ordinary directory' => ['src/.env'];
        yield 'the package\'s own vendor tree' => ['vendor/private/Secret.php'];
        yield 'the vendor directory itself' => ['vendor'];
    }

    /**
     * Each refused path is also put on disk wherever one can be, so what
     * the refusal proves is the syntax rule rather than a missing file.
     */
    #[DataProvider('inadmissiblePathProvider')]
    public function test_a_path_outside_the_admitted_syntax_is_refused(string $path): void
    {
        $this->write('.env', "secret body\n");
        $this->write('.git/config', "secret body\n");
        $this->write('src/.hidden/Secret.php', "secret body\n");
        $this->write('src/.env', "secret body\n");
        $this->write('vendor/private/Secret.php', "secret body\n");

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

    /**
     * A directory name is admitted syntax, so a read of one reaches the
     * regular-file check rather than being turned away as an unadmitted
     * path: the refusal says the target is not a file, which is what it
     * is.
     */
    public function test_a_directory_is_not_a_readable_source_file(): void
    {
        $this->write('src/Http/Controller.php', "body\n");

        self::assertRefusal('source_unreadable', $this->read('src/Http', 1, 200));
        self::assertRefusal('source_unreadable', $this->read('src', 1, 200));
        self::assertRefusal('source_unreadable', $this->search('src', 'body', 1));
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
     * A symlink resolving anywhere the package serves is an ordinary
     * file, one crossing from `src` to a root file included: the
     * refusals around links are about where the target landed, not
     * about links.
     */
    public function test_a_symlink_staying_inside_the_package_is_read(): void
    {
        $this->write('src/A.php', "inside\n");
        $this->write('src/Http/Controller.php', "nested\n");
        $this->write('README.md', "readme\n");

        $this->link('src/B.php', 'src/A.php');
        $this->link('src/Http/Alias.php', 'src/Http/Controller.php');
        $this->link('src/C.php', 'src/Http/Controller.php');
        $this->link('src/Readme.md', 'README.md');

        self::assertSame("inside\n", $this->read('src/B.php', 1, 200)->body['content']);
        self::assertSame("nested\n", $this->read('src/Http/Alias.php', 1, 200)->body['content']);
        self::assertSame("nested\n", $this->read('src/C.php', 1, 200)->body['content']);
        self::assertSame("readme\n", $this->read('src/Readme.md', 1, 200)->body['content']);
    }

    /**
     * The boundary is the install root plus a separator, not a string
     * prefix, so a sibling root whose name merely starts with it is
     * outside the package.
     *
     * @throws JsonException
     */
    public function test_a_sibling_root_sharing_a_name_prefix_stays_outside(): void
    {
        $sibling = $this->root . '-extra';

        if (!mkdir($sibling, 0o700)) {
            throw new RuntimeException("Could not create the sibling root {$sibling}.");
        }

        file_put_contents($sibling . '/Secret.php', "secret body\n");
        symlink($sibling . '/Secret.php', $this->root . '/Reach.php');

        $document = $this->read('Reach.php', 1, 200);

        self::delete($sibling);

        self::assertRefusal('source_unreadable', $document);
        self::assertStringNotContainsString('secret body', $document->toJson());
    }

    /**
     * Where a symlink under an admitted name may not land: the syntax
     * rule is applied again to the path the link resolved to, so a link
     * reaching a hidden name or the package's own vendor tree reads
     * something this tool does not serve however ordinary its own name
     * was.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function escapingLinkProvider(): iterable
    {
        yield 'a source path reaching a hidden root file' => ['src/Link.php', '.env'];
        yield 'a source path reaching a hidden root directory' => ['src/Link.php', '.git/config'];
        yield 'a source path reaching a nested hidden directory' => ['src/Link.php', 'src/.hidden/Secret.php'];
        yield 'a source path reaching the vendor tree' => ['src/Link.php', 'vendor/private/Secret.php'];
        yield 'a root file reaching the vendor tree' => ['Client.php', 'vendor/private/Secret.php'];
        yield 'a readme reaching a hidden root file' => ['README.md', '.env'];
    }

    /**
     * @param string $path the admitted path the call names
     * @param string $target where the link behind it resolves, inside the package
     */
    #[DataProvider('escapingLinkProvider')]
    public function test_a_symlink_reaching_an_unserved_name_is_refused(string $path, string $target): void
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
        $this->write('.env', "secret body\n");
        $this->write('vendor/private/Secret.php', "secret body\n");
        $this->link('src/Link.php', '.env');

        $refusals = [
            'package_unknown' => $this->reader()->search('kinetis/absent', 'src/A.php', 'secret', 1),
            'path_not_admitted' => $this->search('vendor/private/Secret.php', 'secret', 1),
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

        // A symlink onto an unserved name is refused on the search path
        // too, and the file it reached is not searched.
        $escaped = $this->search('src/Link.php', 'secret', 1);

        self::assertRefusal('path_not_admitted', $escaped);
        self::assertStringNotContainsString('secret body', $escaped->toJson());
    }

    /**
     * The listing is the directory as it is: its direct children only,
     * each with the kind that decides the caller's next call, in one
     * order — bytewise by name, which is neither the filesystem's own
     * nor a locale's.
     *
     * @throws JsonException
     */
    public function test_a_listing_reports_the_direct_children_in_bytewise_name_order(): void
    {
        $this->write('src/Zeta.php', "one\n");
        $this->write('src/alpha.php', "two\n");
        $this->write('src/Http/Controller.php', "three\n");
        $this->write('src/Http/Nested/Deep.php', "four\n");

        $document = $this->listing('src');

        self::assertFalse($document->failed);
        self::assertSame(['status', 'package', 'version', 'path', 'entries'], array_keys($document->body));
        self::assertSame([
            'status' => 'ok',
            'package' => self::PACKAGE,
            'version' => '2.4.0',
            'path' => 'src',
            'entries' => [
                // Uppercase sorts before lowercase, which is the whole
                // difference between a bytewise order and a friendly
                // one, and the descendants below Http are not here.
                ['name' => 'Http', 'type' => 'directory'],
                ['name' => 'Zeta.php', 'type' => 'file'],
                ['name' => 'alpha.php', 'type' => 'file'],
            ],
        ], $document->body);
    }

    /**
     * The order is this tool's own rather than the one the directory
     * stores: a larger set, created in the reverse of the order it must
     * come back in, still comes back bytewise by name.
     */
    public function test_the_reported_order_is_not_the_order_the_directory_stores(): void
    {
        $names = [];

        for ($index = 60; $index >= 1; $index--) {
            $names[] = sprintf('A%02d.php', $index);
            $this->write('src/' . $names[count($names) - 1], "body\n");
        }

        sort($names, SORT_STRING);

        self::assertSame($names, array_column($this->listing('src')->body['entries'], 'name'));
    }

    /** A directory beneath an admitted one is listed by naming it; nothing descends on its own. */
    public function test_a_nested_directory_is_listed_by_naming_it(): void
    {
        $this->write('src/Http/Controller.php', "one\n");
        $this->write('src/Http/Nested/Deep.php', "two\n");

        self::assertSame(
            [
                ['name' => 'Controller.php', 'type' => 'file'],
                ['name' => 'Nested', 'type' => 'directory'],
            ],
            $this->listing('src/Http')->body['entries'],
        );
    }

    /**
     * An empty directory is an answer — the directory has nothing in it
     * — not a refusal that leaves a caller guessing which it was.
     */
    public function test_an_empty_directory_is_a_successful_empty_listing(): void
    {
        $document = $this->listing('src');

        self::assertFalse($document->failed);
        self::assertSame([], $document->body['entries']);
    }

    /**
     * Any directory a package keeps its own content in, each listable by
     * its bare name.
     *
     * @return iterable<string, array{string}>
     */
    public static function listableDirectoryProvider(): iterable
    {
        yield 'source' => ['src'];
        yield 'binaries' => ['bin'];
        yield 'resources' => ['resources'];
        yield 'a lib tree' => ['lib'];
        yield 'generated output' => ['generated'];
        yield 'the package\'s own tests' => ['tests'];
    }

    #[DataProvider('listableDirectoryProvider')]
    public function test_every_directory_of_the_package_is_reached_by_its_bare_name(string $path): void
    {
        $this->write($path . '/A.php', "body\n");

        self::assertSame([['name' => 'A.php', 'type' => 'file']], $this->listing($path)->body['entries']);
    }

    /**
     * A listing names a directory under the package root, or the root
     * itself with the one literal `.`. Everything else the read syntax
     * refuses a listing refuses too — `.` included the moment it is a
     * segment rather than the whole path.
     *
     * @return iterable<string, array{string}>
     */
    public static function unlistablePathProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc'];
        yield 'trailing separator' => ['src/'];
        yield 'parent segment' => ['src/../..'];
        yield 'the root token as a segment' => ['./src'];
        yield 'the parent of the package root' => ['..'];
        yield 'backslash separator' => ['src\\Http'];
        yield 'NUL byte' => ["src\0"];
        yield 'hidden root directory' => ['.git'];
        yield 'nested hidden directory' => ['src/.hidden'];
        yield 'the package\'s own vendor tree' => ['vendor'];
        yield 'a directory inside the vendor tree' => ['vendor/private'];
    }

    #[DataProvider('unlistablePathProvider')]
    public function test_a_path_outside_the_listable_syntax_is_refused(string $path): void
    {
        $this->write('.git/config', "secret body\n");
        $this->write('src/.hidden/Secret.php', "secret body\n");
        $this->write('vendor/private/Secret.php', "secret body\n");

        self::assertRefusal('path_not_admitted', $this->listing($path));
    }

    /**
     * `.` is the one path that names the package root, and it is where a
     * caller starts when the layout is unknown. What it reports is the
     * root's own content: the hidden entries and the package's own
     * top-level `vendor` tree are absent, because a listing offers only
     * names a read may name.
     */
    public function test_the_root_token_lists_the_package_root_without_hidden_or_vendor_entries(): void
    {
        $this->write('composer.json', "{}\n");
        $this->write('README.md', "body\n");
        $this->write('AmpHttpClient.php', "<?php\n");
        $this->write('lib/Client.php', "<?php\n");
        $this->write('.env', "secret body\n");
        $this->write('.git/config', "secret body\n");
        $this->write('vendor/private/Secret.php', "secret body\n");

        self::assertSame([
            ['name' => 'AmpHttpClient.php', 'type' => 'file'],
            ['name' => 'README.md', 'type' => 'file'],
            ['name' => 'composer.json', 'type' => 'file'],
            ['name' => 'lib', 'type' => 'directory'],
            ['name' => 'src', 'type' => 'directory'],
        ], $this->listing('.')->body['entries']);
    }

    /**
     * The `vendor` rule is about the first segment alone: a directory of
     * that name deeper inside is the package's own bundled content, so
     * it is listed, listable and readable through.
     */
    public function test_a_vendor_directory_below_the_first_segment_is_the_package_s_own_content(): void
    {
        $this->write('src/vendor/Bundled.php', "bundled\n");

        self::assertSame(
            [['name' => 'vendor', 'type' => 'directory']],
            $this->listing('src')->body['entries'],
        );
        self::assertSame(
            [['name' => 'Bundled.php', 'type' => 'file']],
            $this->listing('src/vendor')->body['entries'],
        );
        self::assertSame("bundled\n", $this->read('src/vendor/Bundled.php', 1, 200)->body['content']);
    }

    public function test_an_uninstalled_package_is_refused_before_a_directory_is_opened(): void
    {
        self::assertRefusal('package_unknown', $this->reader()->list('kinetis/absent', 'src'));
    }

    public function test_an_admitted_directory_that_is_not_there_is_missing(): void
    {
        self::assertRefusal('source_missing', $this->listing('src/Absent'));
    }

    /**
     * An admitted path behind a regular file named the wrong kind of
     * thing, which is a different answer from naming something this
     * tool does not serve.
     */
    public function test_an_admitted_path_behind_a_regular_file_is_not_a_directory(): void
    {
        $this->write('src/A.php', "body\n");
        $this->write('composer.json', "{}\n");

        self::assertRefusal('source_not_directory', $this->listing('src/A.php'));

        // A root file is ordinary readable content now, so naming one in
        // a listing is the wrong kind of thing rather than an unserved
        // path.
        self::assertRefusal('source_not_directory', $this->listing('composer.json'));
    }

    /**
     * A listing offers only what a read of the same name could reach:
     * every child is admitted by its own name and again by the target it
     * resolves to, so a hidden entry, a link out of the package, a link
     * onto a hidden name or into the vendor tree, a dangling one and a
     * special file are absent rather than named. A link that stays
     * inside is an ordinary entry, reported as what it resolves to and
     * usable through that same name.
     *
     * @throws JsonException
     */
    public function test_only_children_a_read_could_reach_are_listed(): void
    {
        $outside = sys_get_temp_dir() . '/orbitron-outside-' . bin2hex(random_bytes(8));
        file_put_contents($outside, "secret body\n");

        $this->write('.env', "secret body\n");
        $this->write('vendor/private/Secret.php', "secret body\n");
        $this->write('src/Real.php', "real\n");
        $this->write('src/Http/Controller.php', "nested\n");
        $this->write('src/.hidden/Secret.php', "secret body\n");

        $this->link('src/Inside.php', 'src/Real.php');
        $this->link('src/Directory', 'src/Http');
        $this->link('src/Hidden.php', '.env');
        $this->link('src/Vendored.php', 'vendor/private/Secret.php');
        $this->link('src/.Link.php', 'src/Real.php');
        symlink($outside, $this->root . '/src/Escape.php');
        symlink($this->root . '/src/Gone.php', $this->root . '/src/Dangling.php');
        self::assertTrue(posix_mkfifo($this->root . '/src/pipe', 0o600));

        $document = $this->listing('src');

        unlink($outside);

        self::assertSame([
            ['name' => 'Directory', 'type' => 'directory'],
            ['name' => 'Http', 'type' => 'directory'],
            ['name' => 'Inside.php', 'type' => 'file'],
            ['name' => 'Real.php', 'type' => 'file'],
        ], $document->body['entries']);
        self::assertStringNotContainsString('secret body', $document->toJson());

        // What a listing named stays reachable through the same
        // resolution policy that admitted it.
        self::assertSame("real\n", $this->read('src/Inside.php', 1, 200)->body['content']);
        self::assertSame(
            [['name' => 'Controller.php', 'type' => 'file']],
            $this->listing('src/Directory')->body['entries'],
        );
    }

    /**
     * A child whose name is not UTF-8 cannot be reported as JSON, and a
     * listing silently missing one entry is not the directory it claims
     * to be — so the whole call refuses, with the code an unreadable
     * directory returns.
     */
    public function test_a_child_name_that_is_not_utf8_refuses_the_listing(): void
    {
        $this->write('src/A.php', "body\n");

        if (@file_put_contents($this->root . "/src/\xC3\x28.php", "body\n") === false) {
            self::markTestSkipped('This filesystem refuses a name that is not UTF-8.');
        }

        self::assertRefusal('source_unreadable', $this->listing('src'));
    }

    /**
     * The ceiling counts what would be reported, so a directory at
     * exactly the cap is a complete answer however many children it
     * also holds that this tool does not serve.
     */
    public function test_exactly_the_admitted_count_is_a_complete_listing(): void
    {
        $this->fill(PackageSourceReader::MAX_ENTRY_COUNT);

        $this->write('.env', "secret body\n");
        $this->write('src/.hidden.php', "secret body\n");
        $this->link('src/Hidden.php', '.env');
        symlink($this->root . '/src/Gone.php', $this->root . '/src/Dangling.php');

        $document = $this->listing('src');

        self::assertFalse($document->failed);
        self::assertCount(PackageSourceReader::MAX_ENTRY_COUNT, $document->body['entries']);
    }

    /**
     * One reportable child past the cap refuses the whole directory: a
     * listing carrying the first 200 of 201 names would answer "what is
     * in here" with something else, and there is no cursor to finish it
     * with.
     *
     * @throws JsonException
     */
    public function test_one_child_past_the_admitted_count_refuses_without_a_partial_list(): void
    {
        $this->fill(PackageSourceReader::MAX_ENTRY_COUNT + 1);

        $document = $this->listing('src');

        self::assertRefusal('directory_oversize', $document);
        self::assertStringNotContainsString('A001.php', $document->toJson());
    }

    /**
     * Every listing refusal is the code alone, exactly as every read
     * refusal is: no path, no root, and no name out of the directory the
     * call stopped in.
     *
     * @throws JsonException
     */
    public function test_no_listing_refusal_carries_a_path_a_root_or_a_name(): void
    {
        $this->write('src/Http/secret body.php', "one\n");
        $this->write('src/A.php', "one\n");
        $this->write('vendor/secret body.php', "one\n");

        $refusals = [
            'package_unknown' => $this->reader()->list('kinetis/absent', 'src'),
            'path_not_admitted' => $this->listing('vendor'),
            'source_missing' => $this->listing('src/Absent'),
            'source_not_directory' => $this->listing('src/A.php'),
        ];

        foreach ($refusals as $code => $document) {
            self::assertRefusal((string) $code, $document);

            $json = $document->toJson();

            self::assertSame(['status', 'code'], array_keys($document->body));
            self::assertStringNotContainsString($this->root, $json);
            self::assertStringNotContainsString(sys_get_temp_dir(), $json);
            self::assertStringNotContainsString('secret body', $json);
        }
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

    private function listing(string $path): Document
    {
        return $this->reader()->list(self::PACKAGE, $path);
    }

    /** $count listable children of `src`, named so their order is plain. */
    private function fill(int $count): void
    {
        for ($index = 1; $index <= $count; $index++) {
            $this->write(sprintf('src/A%03d.php', $index), "body\n");
        }
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
