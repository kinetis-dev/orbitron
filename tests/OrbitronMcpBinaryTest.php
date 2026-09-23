<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Composer\InstalledVersions;
use JsonException;
use Kinetis\McpDocs\DocsApplication;
use Kinetis\McpDocs\DocsCatalogue;
use Kinetis\Orbitron\HealthScaffold;
use Kinetis\Orbitron\Mcp\OrbitronMcpApplication;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The real `vendor/bin/kinetis-orbitron-mcp` as a client launches it: a
 * separate PHP process, a handshake down its stdin, and frames read back
 * off its stdout.
 *
 * It runs through a stand-in for Composer's generated bin proxy, because
 * that proxy is the authority for the consumer root: it sets
 * `_composer_bin_dir` to `<project>/vendor/bin`, and the binary reads the
 * project two directories above it. That is the only way the root is ever
 * decided — no message can name one — so a probe that skipped the proxy
 * would prove the detection a real install uses.
 */
final class OrbitronMcpBinaryTest extends TestCase
{
    private const string BINARY = __DIR__ . '/../bin/kinetis-orbitron-mcp';

    private const string AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

    /** A server that never reached EOF would otherwise hang the suite outright. */
    private const int TIMEOUT_SECONDS = 30;

    private ScaffoldProject $project;

    /**
     * @throws JsonException
     */
    protected function setUp(): void
    {
        if (!is_file(self::AUTOLOAD)) {
            self::markTestSkipped('The package has no vendor/autoload.php to hand the binary.');
        }

        $this->project = new ScaffoldProject();
        $this->writeBinProxy();
        $this->writeInventory();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The whole session an agent runs: initialize, the notification that
     * follows it, both lists, the context resource, a plan, an apply, and
     * a second apply that refuses because the targets now exist.
     */
    public function test_a_real_session_lists_reads_plans_applies_and_then_refuses(): void
    {
        $frames = $this->session([
            '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25",'
            . '"capabilities":{"roots":{"listChanged":true}},"clientInfo":{"name":"claude-code","version":"2.1.273"}}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
            '{"jsonrpc":"2.0","id":2,"method":"resources/list"}',
            '{"jsonrpc":"2.0","id":3,"method":"resources/read","params":{"uri":"'
            . OrbitronMcpApplication::CONTEXT_URI . '"}}',
            '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"orbitron_scaffold_plan"}}',
            '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"orbitron_scaffold_apply"}}',
            '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"orbitron_scaffold_apply"}}',
        ]);

        // Seven requests, one notification answered with nothing.
        self::assertSame([0, 1, 2, 3, 4, 5, 6], array_column($frames, 'id'));

        self::assertSame('2025-06-18', $frames[0]['result']['protocolVersion']);
        self::assertSame(OrbitronMcpApplication::SERVER_NAME, $frames[0]['result']['serverInfo']['name']);

        self::assertSame(
            [
                'orbitron_inspect',
                'orbitron_verify',
                'orbitron_scaffold_plan',
                'orbitron_scaffold_apply',
                OrbitronMcpApplication::SOURCE_TOOL,
                OrbitronMcpApplication::SEARCH_TOOL,
                OrbitronMcpApplication::LIST_TOOL,
                DocsApplication::READ_TOOL,
                DocsApplication::SEARCH_TOOL,
            ],
            array_column($frames[1]['result']['tools'], 'name'),
        );

        $resources = [OrbitronMcpApplication::CONTEXT_URI];

        foreach (DocsCatalogue::pages() as $page) {
            $resources[] = $page->uri();
        }

        self::assertSame($resources, array_column($frames[2]['result']['resources'], 'uri'));
        self::assertStringContainsString('# Orbitron', $frames[3]['result']['contents'][0]['text']);

        self::assertSame('ready', $this->document($frames[4])['status']);
        self::assertSame('created', $this->document($frames[5])['status']);
        self::assertTrue($frames[6]['result']['isError']);
        self::assertSame(['target_exists'], $this->document($frames[6])['codes']);

        // The real files, in the real project, under the project's own
        // namespaces — and importing nothing from Orbitron.
        foreach (HealthScaffold::TARGETS as $target) {
            self::assertFileExists($this->project->path($target));
            self::assertStringNotContainsString('Orbitron', $this->project->contents($target));
        }

        self::assertStringContainsString(
            'namespace ' . rtrim($this->project->production, '\\') . '\\Http;',
            $this->project->contents(HealthScaffold::TARGETS[0]),
        );
    }

    /**
     * A real installed package, read through the real binary: the file
     * that comes back is kinetis/framework's own manifest, at the very
     * version the inventory reports for it, and the frame names no path.
     *
     * Nothing about this is a fixture — the install root comes from the
     * Composer metadata of the vendor tree this suite runs against.
     *
     * @throws JsonException
     */
    public function test_a_real_read_returns_an_installed_kinetis_file_at_its_installed_version(): void
    {
        $frames = $this->session([
            '{"jsonrpc":"2.0","id":0,"method":"tools/call","params":{"name":"orbitron_inspect"}}',
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":{"package":"kinetis/framework","path":"composer.json","lineCount":3}}}',
        ]);

        $installed = $this->document($frames[0])['packages'];
        self::assertIsArray($installed);
        $versions = array_column($installed, 'version', 'name');

        $read = $this->document($frames[1]);

        self::assertFalse($frames[1]['result']['isError']);
        self::assertSame('ok', $read['status']);
        self::assertSame('kinetis/framework', $read['package']);
        self::assertSame($versions['kinetis/framework'], $read['version']);
        self::assertSame(1, $read['startLine']);
        self::assertSame(3, $read['endLine']);
        self::assertTrue($read['hasMore']);
        self::assertStringContainsString('kinetis/framework', $read['content']);
        self::assertStringStartsWith('{', $read['content']);
    }

    /**
     * The search an agent actually runs, through the real binary: a
     * literal in a real installed manifest, reported at the line it is
     * on, with the window tool then reading that line back unchanged.
     *
     * @throws JsonException
     */
    public function test_a_real_search_locates_a_line_the_window_tool_then_reads(): void
    {
        $frames = $this->session([
            '{"jsonrpc":"2.0","id":0,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SEARCH_TOOL
            . '","arguments":{"package":"kinetis/framework","path":"composer.json","query":"\"name\""}}}',
        ]);

        $search = $this->document($frames[0]);

        self::assertFalse($frames[0]['result']['isError']);
        self::assertSame('kinetis/framework', $search['package']);
        self::assertSame('"name"', $search['query']);
        self::assertFalse($search['hasMore']);
        self::assertIsArray($search['matches']);
        self::assertNotSame([], $search['matches']);

        $match = $search['matches'][0];
        self::assertStringContainsString('kinetis/framework', $match['content']);

        $read = $this->document($this->session([
            '{"jsonrpc":"2.0","id":0,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":{"package":"kinetis/framework","path":"composer.json","startLine":'
            . $match['line'] . ',"lineCount":1}}}',
        ])[0]);

        self::assertSame($match['content'], rtrim($read['content'], "\r\n"));
    }

    /**
     * The skeleton launcher hands the checkout's host path to a server
     * whose filesystem cannot see it. The path comes back byte for byte
     * as `checkoutRoot` — never resolved, never required to exist — while
     * the inventory, the layout and the installed source are all read
     * from the real project, which `projectRoot` still names.
     *
     * @throws JsonException
     */
    public function test_a_handed_over_host_path_is_reported_and_never_read(): void
    {
        $hostPath = '/host/' . bin2hex(random_bytes(8)) . "/shop checkout\nsecond line";
        self::assertFileDoesNotExist($hostPath);

        $frames = $this->session([
            '{"jsonrpc":"2.0","id":0,"method":"tools/call","params":{"name":"orbitron_inspect"}}',
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"orbitron_verify"}}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":{"package":"kinetis/framework","path":"composer.json","lineCount":1}}}',
        ], $hostPath);

        $inspect = $this->document($frames[0]);
        $verify = $this->document($frames[1]);

        self::assertSame($hostPath, $inspect['checkoutRoot']);
        self::assertSame(realpath($this->project->root), $inspect['projectRoot']);
        self::assertContains(
            ['name' => 'kinetis/framework', 'version' => InstalledVersions::getPrettyVersion('kinetis/framework')],
            $inspect['packages'],
        );

        self::assertSame('pass', $verify['status']);
        self::assertSame(
            ['production' => $this->project->production, 'test' => $this->project->test],
            $verify['namespaces'],
        );
        self::assertSame('ok', $this->document($frames[2])['status']);
    }

    /**
     * With no launcher in between, the client shares the server's view,
     * so the one physical root is the checkout identity as well.
     *
     * @throws JsonException
     */
    public function test_without_a_handover_both_roots_are_the_physical_project_root(): void
    {
        $inspect = $this->document($this->session([
            '{"jsonrpc":"2.0","id":0,"method":"tools/call","params":{"name":"orbitron_inspect"}}',
        ])[0]);

        self::assertSame(realpath($this->project->root), $inspect['projectRoot']);
        self::assertSame($inspect['projectRoot'], $inspect['checkoutRoot']);
    }

    /**
     * A handover that is not an absolute path is a broken launcher. It
     * stops the process before the protocol loop answers anything, so no
     * client ever receives an identity the launcher did not mean.
     */
    public function test_a_relative_handover_exits_before_the_loop(): void
    {
        [$stdout, $stderr, $exitCode] = $this->execute([
            '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18",'
            . '"capabilities":{},"clientInfo":{"name":"manual","version":"0"}}}',
        ], 'host/shop');

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertSame(
            OrbitronMcpApplication::SERVER_NAME . ': ' . OrbitronMcpApplication::CHECKOUT_ROOT_ENV
            . " must be an absolute path.\n",
            $stderr,
        );
    }

    /**
     * A path the tool does not admit is refused by the real binary too,
     * and the refusal names nothing about this machine.
     *
     * @throws JsonException
     */
    public function test_a_real_read_outside_the_admitted_paths_is_refused_without_a_path(): void
    {
        [$stdout] = $this->execute([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":{"package":"kinetis/framework","path":"../../../etc/passwd"}}}',
        ]);

        $frame = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($frame['result']['isError']);
        self::assertSame(
            ['status' => 'error', 'code' => 'path_not_admitted'],
            json_decode($frame['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString('vendor', $stdout);
    }

    /**
     * Stdout carries JSON-RPC frames, so it is handed to the loop and to
     * nothing else, and the composed documentation server is given stderr
     * to report a failed page fetch on. Read off the binary: producing a
     * real one would mean reaching the network from the suite, and its
     * own contents are asserted against a controlled client in
     * {@see OrbitronMcpApplicationTest}.
     */
    public function test_the_binary_gives_the_documentation_server_stderr_and_stdout_only_to_the_loop(): void
    {
        $source = (string) file_get_contents(self::BINARY);

        self::assertStringContainsString('new DocsApplication(diagnostics: STDERR)', $source);
        self::assertSame(1, preg_match_all('/\bSTDOUT\b/', $source));
    }

    /**
     * Whatever the binary does, stdout carries JSON-RPC frames and
     * nothing else — a stray notice or warning there would corrupt the
     * framing for every message after it.
     */
    public function test_the_binary_writes_only_frames_to_stdout_and_ends_cleanly_at_eof(): void
    {
        [$stdout, $stderr, $exitCode] = $this->execute([
            '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            'not json at all',
            '{"jsonrpc":"2.0","id":2,"method":"ping"}',
        ]);

        self::assertSame(0, $exitCode, 'EOF ends the process normally');
        self::assertSame('', $stderr);

        $lines = array_values(array_filter(explode("\n", $stdout), static fn (string $l): bool => $l !== ''));
        self::assertCount(3, $lines);
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', $lines[0]);
        self::assertSame(-32700, json_decode($lines[1], true)['error']['code']);
        self::assertSame('{"jsonrpc":"2.0","id":2,"result":{}}', $lines[2]);
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    private function document(array $frame): array
    {
        /** @var array<string, mixed> */
        return json_decode($frame['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $messages
     * @return list<array<string, mixed>>
     */
    private function session(array $messages, ?string $checkoutRoot = null): array
    {
        [$stdout, $stderr, $exitCode] = $this->execute($messages, $checkoutRoot);

        self::assertSame('', $stderr, 'the server reported a diagnostic');
        self::assertSame(0, $exitCode);

        $frames = [];

        foreach (explode("\n", $stdout) as $line) {
            if ($line !== '') {
                $frames[] = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
            }
        }

        /** @var list<array<string, mixed>> */
        return $frames;
    }

    /**
     * The binary runs in this suite's environment, less any inherited
     * checkout handover, plus the one a test passes.
     *
     * @param list<string> $messages
     * @return array{string, string, int}
     */
    private function execute(array $messages, ?string $checkoutRoot = null): array
    {
        $environment = getenv();
        unset($environment[OrbitronMcpApplication::CHECKOUT_ROOT_ENV]);

        if ($checkoutRoot !== null) {
            $environment[OrbitronMcpApplication::CHECKOUT_ROOT_ENV] = $checkoutRoot;
        }

        $process = proc_open(
            [PHP_BINARY, $this->project->path('vendor/bin/kinetis-orbitron-mcp')],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the Orbitron MCP binary.');
        }

        fwrite($pipes[0], implode("\n", $messages) . "\n");
        fclose($pipes[0]);

        stream_set_timeout($pipes[1], self::TIMEOUT_SECONDS);
        stream_set_timeout($pipes[2], self::TIMEOUT_SECONDS);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout, $stderr, proc_close($process)];
    }

    /**
     * The generated Composer inventory the launched server reads, built
     * from the set this suite itself runs against.
     *
     * The server reads the consumer project's own
     * `vendor/composer/installed.php`, so the fixture needs one. Every
     * name, version and install root below is copied from the vendor tree
     * this suite runs against — with each root resolved to an absolute
     * path, because the generated file's own roots are written relative
     * to the directory it sits in.
     *
     * A name that is only replaced or provided carries neither field,
     * which is how Composer generates it and what the reader requires.
     */
    private function writeInventory(): void
    {
        $versions = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $path = InstalledVersions::getInstallPath($name);
            $resolved = $path === null ? false : realpath($path);
            $version = InstalledVersions::getPrettyVersion($name);

            $entry = ['dev_requirement' => false];

            if ($version !== null) {
                $entry['pretty_version'] = $version;
            }

            if ($resolved !== false) {
                $entry['install_path'] = $resolved;
            }

            $versions[$name] = $entry;
        }

        $directory = $this->project->path('vendor/composer');

        if (!mkdir($directory, 0o700, true)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        file_put_contents($directory . '/installed.php', '<?php return ' . var_export([
            'root' => InstalledVersions::getRootPackage(),
            'versions' => $versions,
        ], true) . ';');
    }

    /**
     * The stand-in for Composer's generated proxy: the two globals it
     * sets, then the real binary.
     */
    private function writeBinProxy(): void
    {
        $binDir = $this->project->path('vendor/bin');

        if (!mkdir($binDir, 0o700, true)) {
            throw new RuntimeException("Could not create {$binDir}.");
        }

        file_put_contents($binDir . '/kinetis-orbitron-mcp', sprintf(
            "<?php\n\n\$GLOBALS['_composer_bin_dir'] = __DIR__;\n"
            . "\$GLOBALS['_composer_autoload_path'] = %s;\n\nreturn include %s;\n",
            var_export((string) realpath(self::AUTOLOAD), true),
            var_export((string) realpath(self::BINARY), true),
        ));
    }
}
