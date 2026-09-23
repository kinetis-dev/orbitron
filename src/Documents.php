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
    public const int INSPECT_SCHEMA_VERSION = 3;

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
     * The physical checkout being read, the checkout identity its client
     * sees, and its installed `kinetis/*` inventory. The document is never
     * a failure.
     *
     * The detected root is lexical, so it is canonicalized here: a path
     * through a symlink would not identify the checkout this process
     * reads.
     *
     * The checkout root is reported exactly as given and never touches the
     * filesystem: it names the checkout in the client's own view, which a
     * containerized server cannot see.
     *
     * @param string $projectRoot the detected consumer root, never a path a caller chose
     * @param string|null $checkoutRoot the launcher's absolute host path for this checkout, or null
     *        when the client shares this process's view and the physical root is that identity
     * @throws RuntimeException when the root does not resolve to a physical path
     */
    public function inspect(string $projectRoot, ?string $checkoutRoot = null): Document
    {
        $physicalRoot = realpath($projectRoot);

        if ($physicalRoot === false) {
            throw new RuntimeException( // NOSONAR(php:S112) framework-detected root, no caller-specific recovery
                "The project root {$projectRoot} does not resolve to a physical path.",
            );
        }

        return new Document([
            'schemaVersion' => self::INSPECT_SCHEMA_VERSION,
            'orbitronVersion' => $this->packages->orbitronVersion(),
            'projectRoot' => $physicalRoot,
            'checkoutRoot' => $checkoutRoot ?? $physicalRoot,
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
