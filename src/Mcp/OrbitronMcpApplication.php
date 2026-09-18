<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Mcp;

use Kinetis\McpDocs\DocsApplication;
use Kinetis\McpProtocol\Exception\JsonRpcException;
use Kinetis\McpProtocol\McpApplication;
use Kinetis\McpProtocol\ProgressEmitter;
use Kinetis\McpProtocol\ResourceDescription;
use Kinetis\McpProtocol\ResourceResult;
use Kinetis\McpProtocol\ServerInfo;
use Kinetis\McpProtocol\ToolAnnotations;
use Kinetis\McpProtocol\ToolDescription;
use Kinetis\McpProtocol\ToolResult;
use Kinetis\Orbitron\Document;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\ScaffoldMode;
use stdClass;

/**
 * Orbitron's four documents as MCP tools, and its context document plus
 * the Kinetis documentation as MCP resources, over the shared protocol
 * server. One connection is the whole project-local surface an agent
 * needs: there is no second server to register.
 *
 * Every tool call reaches {@see Documents}, the same service the CLI
 * commands adapt: no command is invoked, no output is parsed, and no
 * envelope is built twice. Every `kinetis://docs/*` read reaches the
 * {@see DocsApplication} this object was handed, which owns the fixed
 * catalogue and the bounded fetch. kinetis/mcp-docs remains the
 * framework-agnostic owner of both and is installable on its own; none
 * of it is copied here.
 *
 * The project root and that documentation application are the only
 * things this object holds. No MCP message can name a path, a source
 * body, a URL, an origin, a ref, a template or a command: a tool takes
 * no argument at all, and a resource read selects one entry of a fixed
 * catalogue whose URLs are the documentation server's own constants.
 *
 * `orbitron_scaffold_apply` is the one tool that writes. Selecting it is
 * the whole mutation request, which is why it has no boolean to set: the
 * MCP client's configured approval policy controls whether it runs, and
 * the local process and filesystem permissions remain the authority
 * boundary for it. Reading a documentation page is the one operation
 * that leaves this machine, over HTTPS to that fixed origin.
 *
 * Orbitron does not boot the Kinetis application here, so nothing about
 * running this server registers a route, a listener or a bootstrap.
 */
final readonly class OrbitronMcpApplication implements McpApplication
{
    public const string SERVER_NAME = 'kinetis-orbitron-mcp';

    public const string CONTEXT_URI = 'kinetis://orbitron/context';

    private const string DOCS_ENTRY_URI = 'kinetis://docs/agent-workflow';

    private const string INSTRUCTIONS = 'Orbitron reports what this project has, serves the Kinetis documentation, '
        . 'and can scaffold one fixed health endpoint. Read ' . self::CONTEXT_URI . ' first: it states the harness '
        . 'boundary and the workflow. Then call orbitron_inspect for the installed kinetis/* versions, '
        . 'orbitron_verify for whether the project layout is the one Orbitron supports, and orbitron_scaffold_plan '
        . 'before orbitron_scaffold_apply, which is the only tool that writes. Before changing application code, '
        . 'read ' . self::DOCS_ENTRY_URI . ' and route the task through the pages it names — read them instead of '
        . 'answering about Kinetis from memory. Those pages are published from main and can describe behavior newer '
        . 'than this project has installed, so the versions orbitron_inspect reports and the installed source stay '
        . 'the authority for anything version-sensitive. Installed versions are read once at startup, so restart '
        . 'this server after changing dependencies.';

    /** The input schema all four tools share: an object with no members and nothing else admitted. */
    private const string CLOSED_SCHEMA_DESCRIPTION = 'Takes no arguments.';

    /**
     * @param DocsApplication $docs the documentation server this one
     *        publishes and delegates to, constructed with the diagnostic
     *        stream a failed fetch is reported on — never stdout.
     */
    public function __construct(
        private string $projectRoot,
        private DocsApplication $docs,
        private Documents $documents = new Documents(),
    ) {}

    /**
     * Orbitron's own installed version is what this server reports, the
     * same authority every document carries.
     */
    public function serverInfo(): ServerInfo
    {
        $version = $this->documents->inspect()->body['orbitronVersion'];
        \assert(\is_string($version));

        return new ServerInfo(self::SERVER_NAME, $version, self::INSTRUCTIONS);
    }

    /**
     * @return list<ToolDescription>
     */
    #[\Override]
    public function tools(): array
    {
        return [
            self::tool(
                'orbitron_inspect',
                'Reports the installed kinetis/* packages and their versions as a JSON document. '
                . 'Read once at startup by Composer; restart this server after installing or removing a dependency.',
                self::readOnly(),
            ),
            self::tool(
                'orbitron_verify',
                'Reports whether this project\'s Composer layout is the one Orbitron supports, as a JSON '
                . 'document. Reads the project\'s composer.json through a bounded read and changes nothing. '
                . 'An error means the project is outside what Orbitron assumes, not that the application is broken.',
                self::readOnly(),
            ),
            self::tool(
                'orbitron_scaffold_plan',
                'Reports the health-endpoint scaffold plan as a JSON document: whether the two fixed files can '
                . 'be created, and the stable codes for any refusal. Writes nothing.',
                self::readOnly(),
            ),
            self::tool(
                'orbitron_scaffold_apply',
                'Creates the health-endpoint scaffold: src/Http/HealthController.php and '
                . 'tests/Http/HealthControllerTest.php. This writes to the project. Every precondition is '
                . 'recomputed first, both files are created exclusively and never overwritten, and a second '
                . 'file that cannot be finished removes the first. Running it again refuses, because the '
                . 'targets now exist.',
                new ToolAnnotations(readOnly: false, destructive: true, idempotent: false, openWorld: false),
            ),
        ];
    }

    /**
     * Orbitron's context, then every documentation page, as the one list
     * a client reads. The documentation entries are the catalogue's own —
     * listed here rather than restated, so a page added to
     * kinetis/mcp-docs appears without a change in this package.
     *
     * @return list<ResourceDescription>
     */
    #[\Override]
    public function resources(): array
    {
        return [
            new ResourceDescription(
                self::CONTEXT_URI,
                'Orbitron context',
                'What Orbitron is, what it does not establish, where the authoritative Kinetis guidance lives, '
                . 'the command workflow, and the installed kinetis/* package facts, as Markdown.',
                'text/markdown',
            ),
            ...$this->docs->resources(),
        ];
    }

    #[\Override]
    public function callTool(
        string $name,
        stdClass $arguments,
        ProgressEmitter $progress,
        ?object $context,
    ): ToolResult {
        // Every tool publishes a closed, empty schema, so an argument is
        // a call the tool has no reading of — refused before anything
        // runs rather than silently discarded.
        if (get_object_vars($arguments) !== []) {
            throw JsonRpcException::invalidParams("The \"{$name}\" tool takes no arguments.");
        }

        return self::result(match ($name) {
            'orbitron_inspect' => $this->documents->inspect(),
            'orbitron_verify' => $this->documents->verify($this->projectRoot),
            'orbitron_scaffold_plan' => $this->documents->scaffold($this->projectRoot, ScaffoldMode::Preview),
            'orbitron_scaffold_apply' => $this->documents->scaffold($this->projectRoot, ScaffoldMode::Apply),
            default => throw JsonRpcException::invalidParams("Unknown tool: \"{$name}\"."),
        });
    }

    /**
     * The context document is read locally; every other URI is the
     * documentation server's to answer, including the refusal for one it
     * does not carry.
     */
    #[\Override]
    public function readResource(string $uri, ?object $context): ResourceResult
    {
        if ($uri === self::CONTEXT_URI) {
            return new ResourceResult($uri, 'text/markdown', $this->documents->context());
        }

        return $this->docs->readResource($uri, $context);
    }

    /**
     * The document either way. A refusal or a failed write is a tool that
     * ran and concluded, so it is an MCP error result carrying the same
     * document a success carries — never a transport error that would
     * leave the codes unreadable.
     */
    private static function result(Document $document): ToolResult
    {
        $json = $document->toJson();

        return $document->failed ? ToolResult::error($json) : ToolResult::text($json);
    }

    private static function tool(string $name, string $description, ToolAnnotations $annotations): ToolDescription
    {
        return new ToolDescription(
            $name,
            $description . ' ' . self::CLOSED_SCHEMA_DESCRIPTION,
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            $annotations,
        );
    }

    /**
     * Closed-world because a tool's whole read set is this project's own
     * Composer metadata and manifest: no network, no database, no other
     * system to reach. Reading a documentation resource is the one
     * operation that leaves this machine, and it is not a tool.
     */
    private static function readOnly(): ToolAnnotations
    {
        return new ToolAnnotations(readOnly: true, destructive: false, idempotent: true, openWorld: false);
    }
}
