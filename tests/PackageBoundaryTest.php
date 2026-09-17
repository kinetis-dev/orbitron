<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Console\Attributes\Command;
use Kinetis\Orbitron\Console\ContextCommand;
use Kinetis\Orbitron\Console\InspectCommand;
use Kinetis\Orbitron\Console\ScaffoldCommand;
use Kinetis\Orbitron\Console\VerifyCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;

/**
 * What Orbitron registers, and what its own `src/` is allowed to reach.
 * Both are read off the package itself rather than restated here.
 *
 * The scans below cover Orbitron's own source and nothing beyond it.
 * They are not a claim about the vendor code it calls:
 * `Composer\InstalledVersions::getInstalled()` loads
 * `vendor/composer/installed.php` itself, which together with the
 * project's own `composer.json` is the whole file-read set the
 * documented boundary admits to.
 *
 * Writing is narrower still. One file creates files, one file removes
 * one, and the only mode either opens is the exclusive create the
 * scaffold's two targets need; no production file creates a directory.
 */
final class PackageBoundaryTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../src';

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function commandProvider(): iterable
    {
        yield 'context' => [ContextCommand::class, 'run', 'orbitron:context'];
        yield 'inspect' => [InspectCommand::class, 'run', 'orbitron:inspect'];
        yield 'verify' => [VerifyCommand::class, 'run', 'orbitron:verify'];
        yield 'scaffold' => [ScaffoldCommand::class, 'run', 'orbitron:scaffold'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('commandProvider')]
    public function test_each_command_declares_its_exact_name_and_skips_the_bootstrap_chain(
        string $class,
        string $method,
        string $name,
    ): void {
        $attributes = (new ReflectionMethod($class, $method))->getAttributes(Command::class);

        self::assertCount(1, $attributes);

        $command = $attributes[0]->newInstance();

        self::assertSame($name, $command->name);
        self::assertNotSame('', $command->description);
        self::assertFalse($command->bootstrap);
    }

    /**
     * Registration is the console scan root and nothing else — no
     * bootstrap and no discovery plugin, so installing Orbitron adds
     * nothing to an application's runtime surface. The one binary is the
     * MCP server, which is launched by a client rather than registered
     * with the framework.
     *
     * @throws \JsonException
     */
    public function test_the_package_registers_only_its_console_scan_root_and_the_mcp_binary(): void
    {
        $composer = self::composer();

        self::assertSame(['kinetis' => ['scan' => 'Kinetis\\Orbitron\\Console\\']], $composer['extra']);
        self::assertSame(['bin/kinetis-orbitron-mcp'], $composer['bin']);
        self::assertSame(
            [
                'php' => '^8.4',
                'kinetis/framework' => 'dev-main',
                'kinetis/mcp-protocol' => 'dev-main',
                'composer-runtime-api' => '^2.0',
            ],
            $composer['require'],
        );
    }

    /**
     * Installing the MCP server must not install the application MCP
     * package with it: that one contributes a bootstrap and a discovery
     * plugin, so it would change what a consumer application does at
     * runtime. Orbitron depends on the protocol package alone.
     *
     * @throws \JsonException
     */
    public function test_the_package_does_not_depend_on_the_application_mcp_package(): void
    {
        $composer = self::composer();

        self::assertArrayNotHasKey('kinetis/mcp', $composer['require']);
        self::assertArrayNotHasKey('kinetis/mcp', $composer['require-dev'] ?? []);
    }

    /**
     * @return array<string, mixed>
     * @throws \JsonException
     */
    private static function composer(): array
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);

        /** @var array<string, mixed> */
        return $composer;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenCallProvider(): iterable
    {
        foreach ([
            'network' => ['curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'socket_create', 'file_get_contents'],
            'environment' => ['getenv', 'putenv', 'parse_ini_file'],
            'process' => ['exec', 'shell_exec', 'passthru', 'system', 'proc_open', 'popen', 'pcntl_fork'],
            'write' => ['file_put_contents', 'mkdir', 'rename', 'touch', 'copy', 'chmod', 'symlink'],
        ] as $kind => $functions) {
            foreach ($functions as $function) {
                yield "{$kind}: {$function}()" => [$function];
            }
        }
    }

    #[DataProvider('forbiddenCallProvider')]
    public function test_production_code_calls_no_network_environment_process_or_write_function(string $function): void
    {
        foreach (self::sourceFiles() as $path => $contents) {
            self::assertSame(
                0,
                preg_match('/\b' . preg_quote($function, '/') . '\s*\(/', $contents),
                "{$path} calls {$function}().",
            );
        }
    }

    /**
     * `fopen()` is the one exception to the write list above:
     * `file_get_contents()` is unbounded and stays forbidden, and a file
     * that must not already exist cannot be created any other way. Two
     * calls exist, in two files, and each names the one mode it is for.
     */
    public function test_the_only_files_opened_are_the_manifest_read_and_the_exclusive_create(): void
    {
        $modes = ['ProjectLayout.php' => 'fopen($path, \'rb\')', 'FileScaffoldWriter.php' => 'fopen($path, \'x+b\')'];

        foreach (self::sourceFiles() as $path => $contents) {
            $opens = preg_match_all('/fopen\s*\(/', $contents);

            if (isset($modes[$path])) {
                self::assertSame(1, $opens);
                self::assertStringContainsString($modes[$path], $contents);

                continue;
            }

            self::assertSame(0, $opens, "{$path} opens a file.");
        }
    }

    /**
     * Removal exists for one reason — undoing a scaffold that did not
     * finish — so it lives with the create it undoes and nowhere else.
     */
    public function test_only_the_scaffold_writer_removes_a_file(): void
    {
        foreach (self::sourceFiles() as $path => $contents) {
            $removals = preg_match_all('/unlink\s*\(/', $contents);

            if ($path === 'FileScaffoldWriter.php') {
                self::assertSame(1, $removals);

                continue;
            }

            self::assertSame(0, $removals, "{$path} removes a file.");
        }
    }

    /**
     * A command never reads an argument itself: `Console\Invocation` is
     * the one place the parsed invocation is inspected, and it answers a
     * format and a flag. No value from a command line can become a path.
     */
    public function test_only_the_invocation_surface_reads_the_parsed_arguments(): void
    {
        foreach (self::sourceFiles() as $path => $contents) {
            if ($path === 'Console/Invocation.php') {
                continue;
            }

            self::assertSame(
                0,
                preg_match('/\$arguments->/', $contents),
                "{$path} reads the parsed arguments directly.",
            );
        }
    }

    /**
     * The read is bounded by construction: one byte past the admitted
     * size is all that separates an accepted manifest from an oversized
     * one, and nothing ever reads the stream to its end.
     */
    public function test_the_manifest_read_is_bounded_by_the_admitted_size_plus_one_byte(): void
    {
        $reader = self::sourceFiles()['ProjectLayout.php'];

        self::assertStringContainsString(
            'stream_get_contents($handle, self::MAX_MANIFEST_BYTES + 1)',
            $reader,
        );
        self::assertSame(1, preg_match_all('/stream_get_contents\s*\(/', $reader));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function superglobalProvider(): iterable
    {
        foreach (['_ENV', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('superglobalProvider')]
    public function test_production_code_reads_no_superglobal(string $name): void
    {
        foreach (self::sourceFiles() as $path => $contents) {
            self::assertStringNotContainsString('$' . $name, $contents, "{$path} reads \${$name}.");
        }
    }

    /**
     * The exact set of types the production code imports. An application
     * boot dependency — a container scope, a Config, a package bootstrap
     * — would have to appear here first, and so would anything from
     * `kinetis/mcp`, whose installation registers both.
     */
    public function test_production_code_imports_only_the_command_contract_and_composers_installed_set(): void
    {
        $imported = [];

        foreach (self::sourceFiles() as $contents) {
            preg_match_all('/^use ([^;]+);$/m', $contents, $matches);

            $imported = [...$imported, ...$matches[1]];
        }

        $imported = array_values(array_unique($imported));
        sort($imported);

        self::assertSame(
            [
                'Composer\InstalledVersions',
                'JsonException',
                'Kinetis\Console\Attributes\Command',
                'Kinetis\Console\CommandArguments',
                'Kinetis\McpProtocol\Exception\JsonRpcException',
                'Kinetis\McpProtocol\McpApplication',
                'Kinetis\McpProtocol\ProgressEmitter',
                'Kinetis\McpProtocol\ResourceDescription',
                'Kinetis\McpProtocol\ResourceResult',
                'Kinetis\McpProtocol\ServerInfo',
                'Kinetis\McpProtocol\ToolAnnotations',
                'Kinetis\McpProtocol\ToolDescription',
                'Kinetis\McpProtocol\ToolResult',
                'Kinetis\Orbitron\Document',
                'Kinetis\Orbitron\Documents',
                'Kinetis\Orbitron\JsonDocument',
                'Kinetis\Orbitron\ScaffoldMode',
                'Kinetis\Runtime\ProjectRoot',
                'RuntimeException',
                'stdClass',
            ],
            $imported,
        );
    }

    /**
     * Every production file, keyed by its path under `src/`.
     *
     * @return array<string, string>
     */
    private static function sourceFiles(): array
    {
        $root = (string) realpath(self::SOURCE);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        $contents = [];

        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);

            if ($file->getExtension() === 'php') {
                $contents[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        self::assertNotSame([], $contents);

        return $contents;
    }
}
