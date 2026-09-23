<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

use RuntimeException;

/**
 * Every Orbitron document, built from the project as it is now.
 *
 * This is the one place a document's shape is decided. The CLI commands
 * and the MCP server are both thin adapters over it: neither builds an
 * envelope of its own, and MCP never invokes a command or reads its
 * output, so the two surfaces cannot drift apart.
 *
 * Nothing is memoized. Every call re-reads the bounded project inputs it
 * needs and keeps no plan, file content or diagnostic afterwards. The
 * installed-package inventory is the snapshot this object was handed and
 * never refreshed here: a command constructs one per invocation, and the
 * MCP server constructs one per operation, so neither surface reports a
 * set older than the call.
 */
final readonly class Documents
{
    /** The inventory envelope's own version, moved only when its shape changes. */
    public const int INSPECT_SCHEMA_VERSION = 2;

    /** The verification envelope's own version, moved only when its shape changes. */
    public const int VERIFY_SCHEMA_VERSION = 1;

    /** The scaffold envelope's own version, moved only when its shape changes. */
    public const int SCAFFOLD_SCHEMA_VERSION = 1;

    public function __construct(
        private InstalledPackages $packages = new InstalledPackages(),
        private HealthScaffold $scaffold = new HealthScaffold(),
    ) {}

    /**
     * The context document as Markdown — the one format it has. {@see
     * Context} renders the same array either way, so the JSON form the
     * CLI also offers cannot state anything different.
     */
    public function context(): string
    {
        return new Context($this->packages)->toMarkdown();
    }

    /**
     * @return array{
     *     orbitronVersion: string,
     *     harness: array{name: string, role: string, limits: list<string>},
     *     guides: list<array{title: string, url: string}>,
     *     workflow: list<string>,
     *     commands: list<array{name: string, formats: list<string>, effect: string}>,
     *     mcp: array{binary: string, protocolVersion: string, tools: list<array{name: string, effect: string}>, resources: list<array{uri: string, effect: string}>},
     *     server: string,
     *     launcher: string,
     *     packages: list<array{name: string, version: string}>,
     * }
     */
    public function contextBody(): array
    {
        return new Context($this->packages)->toArray();
    }

    /**
     * The physical checkout being read and its installed `kinetis/*`
     * inventory. The document is never a failure.
     *
     * The detected root is lexical, so it is canonicalized here: two
     * checkouts with the same lock state differ only by this path, and a
     * path through a symlink would not identify the checkout.
     *
     * @param string $projectRoot the detected consumer root, never a path a caller chose
     * @throws RuntimeException when the root does not resolve to a physical path
     */
    public function inspect(string $projectRoot): Document
    {
        $physicalRoot = realpath($projectRoot);

        if ($physicalRoot === false) {
            throw new RuntimeException("The project root {$projectRoot} does not resolve to a physical path.");
        }

        return new Document([
            'schemaVersion' => self::INSPECT_SCHEMA_VERSION,
            'orbitronVersion' => $this->packages->orbitronVersion(),
            'projectRoot' => $physicalRoot,
            'packages' => $this->packages->records(),
        ], false);
    }

    /**
     * @param string $projectRoot the detected consumer root, never a path a caller chose
     */
    public function verify(string $projectRoot): Document
    {
        $layout = ProjectLayout::read($projectRoot);

        $checks = [];

        foreach ($layout->checks() as $check) {
            $checks[] = ['name' => $check->name, 'state' => $check->state->value, 'code' => $check->code];
        }

        return new Document([
            'schemaVersion' => self::VERIFY_SCHEMA_VERSION,
            'orbitronVersion' => $this->packages->orbitronVersion(),
            'status' => $layout->hasError() ? 'error' : 'pass',
            'checks' => $checks,
            'namespaces' => $layout->namespaces(),
        ], $layout->hasError());
    }

    /**
     * The scaffold plan, or the result of applying it. Apply is the only
     * operation here that writes, and it recomputes every precondition
     * itself rather than trusting a plan built earlier.
     *
     * @param string $projectRoot the detected consumer root, never a path a caller chose
     */
    public function scaffold(string $projectRoot, ScaffoldMode $mode): Document
    {
        $outcome = $mode === ScaffoldMode::Apply
            ? $this->scaffold->apply($projectRoot)
            : $this->scaffold->preview($projectRoot);

        return new Document([
            'schemaVersion' => self::SCAFFOLD_SCHEMA_VERSION,
            'orbitronVersion' => $this->packages->orbitronVersion(),
            'mode' => $outcome->mode->value,
            'status' => $outcome->status->value,
            'codes' => $outcome->codes,
            'targets' => HealthScaffold::TARGETS,
            'remainingFiles' => $outcome->remainingFiles,
        ], !$outcome->succeeded());
    }
}
