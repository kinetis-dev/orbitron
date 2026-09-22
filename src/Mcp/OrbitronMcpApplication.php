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
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageSourceReader;
use Kinetis\Orbitron\ScaffoldMode;
use stdClass;

/**
 * Orbitron's four documents, one installed-source window, one
 * installed-source search, one installed-source directory listing and
 * the documentation window as MCP tools, and its context document plus
 * the Kinetis documentation as MCP resources, over the shared protocol
 * server. One connection is the whole project-local surface an agent
 * needs: there is no second server to register.
 *
 * Every document tool call reaches {@see Documents}, the same service
 * the CLI commands adapt: no command is invoked, no output is parsed,
 * and no envelope is built twice. Every `kinetis://docs/*` read, and
 * every call to the documentation window, reaches the
 * {@see DocsApplication} this object was handed, which owns the fixed
 * catalogue, the bounded fetch, and that tool's description, schema and
 * validation alike. kinetis/mcp-docs remains the framework-agnostic
 * owner of all of it and is installable on its own; none of it is
 * copied here.
 *
 * The project root and that documentation application are the only
 * things this object holds; the inventory, the documents built from it
 * and the source reader are created for one operation and discarded
 * with its response. No MCP message can name a source body, a URL, an
 * origin, a ref, a template, a command or the inventory path: four tools
 * take no argument at all, a resource read and the documentation window
 * each select one entry of a fixed catalogue whose URLs are the
 * documentation server's own constants, and the three that take a path
 * admit it only as a relative name under one installed package: each
 * schema is validated here in full before the package lookup, and the
 * path itself is admitted against a fixed set of locations, with the
 * resolved target re-admitted, before anything reaches the filesystem.
 * The three share that validation, and {@see PackageSourceReader} is the
 * one place a file or a directory behind any of them is opened.
 *
 * `orbitron_scaffold_apply` is the one tool that writes. Selecting it is
 * the whole mutation request, which is why it has no boolean to set: the
 * MCP client's configured approval policy controls whether it runs, and
 * the local process and filesystem permissions remain the authority
 * boundary for it. Reading a documentation page, as a window or as a
 * resource, is the one operation that leaves this machine, over HTTPS to
 * that fixed origin.
 *
 * Orbitron does not boot the Kinetis application here, so nothing about
 * running this server registers a route, a listener or a bootstrap.
 */
final readonly class OrbitronMcpApplication implements McpApplication
{
    public const string SERVER_NAME = 'kinetis-orbitron-mcp';

    public const string CONTEXT_URI = 'kinetis://orbitron/context';

    public const string SOURCE_TOOL = 'orbitron_read_package_source';

    public const string SEARCH_TOOL = 'orbitron_search_package_source';

    public const string LIST_TOOL = 'orbitron_list_package_source';

    private const string DOCS_ENTRY_URI = 'kinetis://docs/agent-workflow';

    private const string INSTRUCTIONS = 'Orbitron reports what this project has, serves the Kinetis documentation, '
        . 'and can scaffold one fixed health endpoint. Read ' . self::CONTEXT_URI . ' first: it states the harness '
        . 'boundary and the workflow. Then call orbitron_inspect for the installed kinetis/* versions, '
        . 'orbitron_verify for whether the project layout is the one Orbitron supports, and orbitron_scaffold_plan '
        . 'before orbitron_scaffold_apply, which is the only tool that writes. Before changing application code, '
        . 'read ' . self::DOCS_ENTRY_URI . ' and route the task through the pages it names — read them instead of '
        . 'answering about Kinetis from memory. Read a page by calling ' . DocsApplication::READ_TOOL . ' with its '
        . 'URI from line 1 and continuing from the line it reports, only while the section you were routed to or a '
        . 'named unknown is unresolved; read it whole as a resource when the complete page is what you need. '
        . 'Those pages are published from main and can describe behavior newer than this project has installed, '
        . 'so the versions orbitron_inspect reports and the installed source stay '
        . 'the authority for anything version-sensitive. A completed composer require or remove is visible to the '
        . 'next call, so nothing has to be restarted or reconnected. Call orbitron_read_package_source to read a '
        . 'window of an installed package\'s own source, which is the authority whenever a page and the installed '
        . 'version could differ, and whenever an exact dependency\'s behavior is what the task turns on. Any '
        . 'package this project really installed is readable, not only kinetis/*: orbitron_inspect names the '
        . 'kinetis/* ones, and the project\'s own composer.lock names every other. When the file is known but '
        . 'the relevant line is not, call orbitron_search_package_source for a literal string in that file and '
        . 'read a window around a line it reports: derive the file from the class and the package\'s own '
        . 'composer.json autoload map, or search that package\'s README.md for the option or term to find the '
        . 'file. When the package is known but the file is not, call orbitron_list_package_source for the '
        . 'direct children of the package root, named as ".", or of any directory under it, and read or '
        . 'search a file it names. Read vendor/ directly only when none of those yields a file, or a tool '
        . 'refuses.';

    /** The input schema the four document tools share: an object with no members and nothing else admitted. */
    private const string CLOSED_SCHEMA_DESCRIPTION = 'Takes no arguments.';

    /**
     * @param DocsApplication $docs the documentation server this one
     *        publishes and delegates to, constructed with the diagnostic
     *        stream a failed fetch is reported on — never stdout.
     */
    public function __construct(
        private string $projectRoot,
        private DocsApplication $docs,
    ) {}

    /**
     * One fresh immutable snapshot of this project's installed set, for
     * the one operation that asked for it.
     *
     * A server outlives a Composer dependency change, so a snapshot held
     * on this object would report the set the process started with.
     * Every operation reads the project's generated inventory again and
     * lets it go with the response: nothing is watched, polled, memoized
     * or carried into the next call, and an inventory that is absent or
     * malformed fails the operation rather than answering from an older
     * one.
     *
     * One operation takes exactly one snapshot, so a document's reported
     * version and the source a read opens cannot come from two different
     * inventories.
     */
    private function packages(): InstalledPackages
    {
        return InstalledPackages::fromProject($this->projectRoot);
    }

    /** The documents of the one snapshot the calling operation takes. */
    private function documents(): Documents
    {
        return new Documents($this->packages());
    }

    /**
     * Orbitron's own installed version is what this server reports, the
     * same authority every document carries.
     */
    public function serverInfo(): ServerInfo
    {
        $version = $this->documents()->inspect()->body['orbitronVersion'];
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
                . 'Read from this project\'s Composer inventory on every call, so a completed dependency change '
                . 'shows up here without restarting or reconnecting.',
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
            new ToolDescription(
                self::SOURCE_TOOL,
                'Reports one window of one file of one installed package as a JSON document: that package\'s '
                . 'own source, at the version this project has installed, which is the authority when a '
                . 'documentation page could describe a newer release and when an exact dependency\'s behavior is '
                . 'what the task turns on. Reach for a kinetis/* package first; any other installed dependency is '
                . 'readable the same way. Takes the package name, a path to any file under that package\'s '
                . 'install root — a root-mapped class sitting beside composer.json included — and an optional '
                . 'window. A hidden name and that package\'s own top-level vendor/ are not served, and this '
                . 'project\'s own source is not readable through it. Reads nothing else and writes nothing.',
                [
                    'type' => 'object',
                    'properties' => [
                        'package' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'description' => 'An installed package name: a kinetis/* one as orbitron_inspect '
                                . 'reports it, or any other dependency as this project\'s composer.lock names it.',
                        ],
                        'path' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => PackageSourceReader::MAX_PATH_LENGTH,
                            'description' => 'The file, relative to the package root, with / separators.',
                        ],
                        'startLine' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'default' => 1,
                            'description' => 'The first line to return, counting from 1.',
                        ],
                        'lineCount' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => PackageSourceReader::MAX_LINE_COUNT,
                            'default' => PackageSourceReader::MAX_LINE_COUNT,
                            'description' => 'How many lines to return.',
                        ],
                    ],
                    'required' => ['package', 'path'],
                    'additionalProperties' => false,
                ],
                self::readOnly(),
            ),
            new ToolDescription(
                self::SEARCH_TOOL,
                'Reports every line of one file of one installed package that contains a literal '
                . 'string, as a JSON document: the line numbers and the lines themselves, at the version this '
                . 'project has installed. Use it when the file is known but the line is not — derive the file '
                . 'from the class and that package\'s own composer.json autoload map, or search its README.md for '
                . 'the option or term — then read a window around a line it reports with '
                . self::SOURCE_TOOL . '. Takes the same package name and path, the exact string to look for, and '
                . 'an optional first line. The search is case-sensitive and literal, with no pattern, and it '
                . 'searches the one file it is given rather than a directory or a package. Reads nothing else '
                . 'and writes nothing.',
                [
                    'type' => 'object',
                    'properties' => [
                        'package' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'description' => 'An installed package name: a kinetis/* one as orbitron_inspect '
                                . 'reports it, or any other dependency as this project\'s composer.lock names it.',
                        ],
                        'path' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => PackageSourceReader::MAX_PATH_LENGTH,
                            'description' => 'The file, relative to the package root, with / separators.',
                        ],
                        'query' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => PackageSourceReader::MAX_QUERY_LENGTH,
                            'description' => 'The exact string a line must contain, matched case-sensitively.',
                        ],
                        'startLine' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'default' => 1,
                            'description' => 'The first line to scan, counting from 1.',
                        ],
                    ],
                    'required' => ['package', 'path', 'query'],
                    'additionalProperties' => false,
                ],
                self::readOnly(),
            ),
            new ToolDescription(
                self::LIST_TOOL,
                'Reports the direct children of one directory of one installed package as a JSON '
                . 'document: each child\'s name and whether it is a file or a directory, at the version this '
                . 'project has installed. Use it when the package is known but the file is not, then read or '
                . 'search a file it names. Takes the package name and a path naming any directory under that '
                . 'package\'s install root, or "." for the root itself. It lists that '
                . 'one directory and nothing under it: no recursion, no pattern, no filter, no paging. A directory '
                . 'of more than ' . PackageSourceReader::MAX_ENTRY_COUNT . ' reportable children is refused whole '
                . 'rather than reported in part. Reads nothing else and writes nothing.',
                [
                    'type' => 'object',
                    'properties' => [
                        'package' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'description' => 'An installed package name: a kinetis/* one as orbitron_inspect '
                                . 'reports it, or any other dependency as this project\'s composer.lock names it.',
                        ],
                        'path' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => PackageSourceReader::MAX_PATH_LENGTH,
                            'description' => 'The directory, relative to the package root, with / separators; '
                                . '"." is the package root itself.',
                        ],
                    ],
                    'required' => ['package', 'path'],
                    'additionalProperties' => false,
                ],
                self::readOnly(),
            ),
            // Published exactly as kinetis/mcp-docs authors it — name,
            // description, schema and annotations — and every call to it
            // is handed back to that application below. Restating any of
            // it here would give a client two accounts of one tool.
            DocsApplication::readTool(),
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
        // The three tools that take arguments validate their whole
        // closed schema here, before a name or a path reaches a lookup
        // or the filesystem: a call a schema has no reading of is a
        // protocol error, not a refusal document.
        if ($name === self::SOURCE_TOOL) {
            [$package, $path, $startLine, $lineCount] = self::sourceArguments($arguments);
            $reader = new PackageSourceReader($this->packages());

            return self::result($reader->read($package, $path, $startLine, $lineCount));
        }

        if ($name === self::SEARCH_TOOL) {
            [$package, $path, $query, $startLine] = self::searchArguments($arguments);
            $reader = new PackageSourceReader($this->packages());

            return self::result($reader->search($package, $path, $query, $startLine));
        }

        if ($name === self::LIST_TOOL) {
            [$package, $path] = self::listArguments($arguments);
            $reader = new PackageSourceReader($this->packages());

            return self::result($reader->list($package, $path));
        }

        // The documentation window is the documentation server's own
        // tool: its schema, its validation, its catalogue and its fetch.
        // Nothing about the call is read or rewritten on the way through.
        if ($name === DocsApplication::READ_TOOL) {
            return $this->docs->callTool($name, $arguments, $progress, $context);
        }

        // Every other tool publishes a closed, empty schema, so an
        // argument is a call the tool has no reading of — refused before
        // anything runs rather than silently discarded.
        if (get_object_vars($arguments) !== []) {
            throw JsonRpcException::invalidParams("The \"{$name}\" tool takes no arguments.");
        }

        // A match evaluates the one arm it selected, so a name no tool
        // answers is refused here before the inventory is read: whether
        // this project has a readable one is not what makes an unknown
        // tool invalid, and must not turn that refusal into an internal
        // error. The selected arm takes the operation's one snapshot.
        return self::result(match ($name) {
            'orbitron_inspect' => $this->documents()->inspect(),
            'orbitron_verify' => $this->documents()->verify($this->projectRoot),
            'orbitron_scaffold_plan' => $this->documents()->scaffold($this->projectRoot, ScaffoldMode::Preview),
            'orbitron_scaffold_apply' => $this->documents()->scaffold($this->projectRoot, ScaffoldMode::Apply),
            default => throw JsonRpcException::invalidParams("Unknown tool: \"{$name}\"."),
        });
    }

    /**
     * The window call's arguments, each member validated for presence,
     * type, range and length, and every key the schema does not name
     * refused.
     *
     * @return array{string, string, int, int}
     */
    private static function sourceArguments(stdClass $arguments): array
    {
        $values = self::members($arguments, ['package', 'path', 'startLine', 'lineCount']);
        $package = self::text($values, 'package');
        $path = self::text($values, 'path', PackageSourceReader::MAX_PATH_LENGTH);
        $startLine = self::startLine($values);

        $lineCount = \array_key_exists('lineCount', $values)
            ? $values['lineCount']
            : PackageSourceReader::MAX_LINE_COUNT;

        if (!\is_int($lineCount) || $lineCount < 1 || $lineCount > PackageSourceReader::MAX_LINE_COUNT) {
            throw JsonRpcException::invalidParams(
                '"lineCount" must be an integer between 1 and ' . PackageSourceReader::MAX_LINE_COUNT . '.',
            );
        }

        return [$package, $path, $startLine, $lineCount];
    }

    /**
     * The search call's arguments, validated the same way and from the
     * same helpers: every call that names installed source admits one
     * package name and one path, so none can be reachable with
     * something another refuses.
     *
     * @return array{string, string, string, int}
     */
    private static function searchArguments(stdClass $arguments): array
    {
        $values = self::members($arguments, ['package', 'path', 'query', 'startLine']);

        return [
            self::text($values, 'package'),
            self::text($values, 'path', PackageSourceReader::MAX_PATH_LENGTH),
            self::text($values, 'query', PackageSourceReader::MAX_QUERY_LENGTH),
            self::startLine($values),
        ];
    }

    /**
     * The listing call's arguments: the package name and the path, from
     * the same helpers again, and nothing else — a listing has no
     * window, query, depth or filter to take.
     *
     * @return array{string, string}
     */
    private static function listArguments(stdClass $arguments): array
    {
        $values = self::members($arguments, ['package', 'path']);

        return [
            self::text($values, 'package'),
            self::text($values, 'path', PackageSourceReader::MAX_PATH_LENGTH),
        ];
    }

    /**
     * The named members of the call, with every key the schema does not
     * name refused.
     *
     * @param list<string> $known
     * @return array<string, mixed>
     */
    private static function members(stdClass $arguments, array $known): array
    {
        $values = get_object_vars($arguments);
        $unknown = array_diff(array_keys($values), $known);

        if ($unknown !== []) {
            throw JsonRpcException::invalidParams(
                'Unknown argument: "' . implode('", "', $unknown) . '".',
            );
        }

        return $values;
    }

    /**
     * One required string member, present, non-empty, and within the
     * maximum its schema publishes.
     *
     * JSON Schema counts maxLength in characters, so the check that
     * enforces it must count the same units: `strlen()` would refuse a
     * value the published schema admits as soon as it carries a
     * multi-byte character. `/./us` counts code points without requiring
     * ext-mbstring, and returns false only for a subject that is not
     * UTF-8 — which a decoded JSON string cannot be.
     *
     * @param array<string, mixed> $values
     */
    private static function text(array $values, string $member, ?int $maximum = null): string
    {
        $value = $values[$member] ?? throw JsonRpcException::invalidParams("\"{$member}\" is required.");

        if (!\is_string($value) || $value === '') {
            throw JsonRpcException::invalidParams("\"{$member}\" must be a non-empty string.");
        }

        if ($maximum !== null) {
            $length = preg_match_all('/./us', $value);

            if ($length === false || $length > $maximum) {
                throw JsonRpcException::invalidParams("\"{$member}\" must be at most {$maximum} characters.");
            }
        }

        return $value;
    }

    /**
     * The optional first line the window and the search count from,
     * defaulting to the first line of the file.
     *
     * @param array<string, mixed> $values
     */
    private static function startLine(array $values): int
    {
        $startLine = \array_key_exists('startLine', $values) ? $values['startLine'] : 1;

        if (!\is_int($startLine) || $startLine < 1) {
            throw JsonRpcException::invalidParams('"startLine" must be an integer of at least 1.');
        }

        return $startLine;
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
            return new ResourceResult($uri, 'text/markdown', $this->documents()->context());
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
     * Closed-world because these tools' whole read set is this project's
     * own Composer metadata and manifest, and the installed source
     * beneath the roots that metadata names: no network, no database, no
     * other system to reach. The documentation window is the one tool
     * that leaves this machine, and kinetis/mcp-docs annotates it
     * open-world itself.
     */
    private static function readOnly(): ToolAnnotations
    {
        return new ToolAnnotations(readOnly: true, destructive: false, idempotent: true, openWorld: false);
    }
}
