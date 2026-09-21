<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use JsonException;
use Kinetis\McpDocs\DocsApplication;
use Kinetis\McpDocs\DocsCatalogue;
use Kinetis\McpDocs\DocsFetcher;
use Kinetis\McpProtocol\Exception\JsonRpcException;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ProgressEmitter;
use Kinetis\McpProtocol\StdioLoop;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\HealthScaffold;
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\Mcp\OrbitronMcpApplication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Orbitron over MCP, driven through the shared stdio loop so what is
 * asserted is the frames a client reads.
 *
 * The documents themselves are proved by the command suites; what belongs
 * here is that MCP reaches the same ones without running a command, that
 * the mutating tool is labelled and behaves as labelled, and that no
 * message can widen what the server touches.
 *
 * The documentation half runs against kinetis/mcp-docs' real
 * DocsApplication and DocsCatalogue over a Symfony MockHttpClient, so the
 * catalogue, the source URL and the failure vocabulary asserted here are
 * that package's own rather than a copy. What its fetcher does with a
 * response is proved in its own suite; what belongs here is that Orbitron
 * publishes and delegates to it without changing any of it.
 */
final class OrbitronMcpApplicationTest extends TestCase
{
    /** @var list<string> every tool this server publishes, in the order it publishes them */
    private const array TOOLS = [
        'orbitron_inspect',
        'orbitron_verify',
        'orbitron_scaffold_plan',
        'orbitron_scaffold_apply',
        OrbitronMcpApplication::SOURCE_TOOL,
        OrbitronMcpApplication::SEARCH_TOOL,
        OrbitronMcpApplication::LIST_TOOL,
        DocsApplication::READ_TOOL,
    ];

    /** The one package the fixture project installs beyond Orbitron and the framework. */
    private const string PACKAGE = 'kinetis/fixture';

    private ScaffoldProject $project;

    /** @var list<MockResponse> what the composed documentation server's client answers with, in order */
    private array $responses = [];

    /** @var list<array{string, string}> the method and URL of every request it made */
    private array $requests = [];

    /** @var resource the stream the composed server reports a failed fetch on — the binary's stderr */
    private $diagnostics;

    /** The install root the one fixture package is read from; a test points it somewhere else. */
    private string $packageRoot;

    /**
     * @throws JsonException
     */
    protected function setUp(): void
    {
        $this->project = new ScaffoldProject();
        $this->packageRoot = $this->project->root;
        $this->writeInventory();
        $this->responses = [];
        $this->requests = [];

        $diagnostics = fopen('php://memory', 'r+');
        self::assertIsResource($diagnostics);
        $this->diagnostics = $diagnostics;
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        fclose($this->diagnostics);
    }

    public function test_initialize_names_orbitron_and_advertises_both_features(): void
    {
        $result = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":'
            . '"2025-11-25","capabilities":{},"clientInfo":{"name":"claude-code","version":"2.1.273"}}}'])[0]['result'];

        self::assertSame('2025-06-18', $result['protocolVersion']);
        self::assertSame(OrbitronMcpApplication::SERVER_NAME, $result['serverInfo']['name']);
        self::assertSame('1.0.0', $result['serverInfo']['version']);
        self::assertSame(['tools', 'resources'], array_keys($result['capabilities']));
        self::assertStringContainsString(OrbitronMcpApplication::CONTEXT_URI, $result['instructions']);
    }

    public function test_the_document_tools_are_published_with_closed_empty_schemas(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];

        self::assertSame(self::TOOLS, array_column($tools, 'name'));

        foreach (array_slice($tools, 0, 4) as $tool) {
            self::assertSame(
                ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                $tool['inputSchema'],
            );
        }
    }

    /**
     * The window tool publishes the whole closed schema a client
     * validates against, and the adapter enforces the same bounds
     * itself — the tests below prove it does not rely on the client
     * having done so.
     */
    public function test_the_source_tool_publishes_a_closed_schema_with_its_bounds(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];
        $schema = $tools[4]['inputSchema'];

        self::assertSame(['package', 'path'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['package', 'path', 'startLine', 'lineCount'], array_keys($schema['properties']));
        self::assertSame(1, $schema['properties']['package']['minLength']);
        self::assertSame(1, $schema['properties']['path']['minLength']);
        self::assertSame(256, $schema['properties']['path']['maxLength']);
        self::assertSame('integer', $schema['properties']['startLine']['type']);
        self::assertSame(1, $schema['properties']['startLine']['minimum']);
        self::assertSame(1, $schema['properties']['startLine']['default']);
        self::assertSame(1, $schema['properties']['lineCount']['minimum']);
        self::assertSame(200, $schema['properties']['lineCount']['maximum']);
        self::assertSame(200, $schema['properties']['lineCount']['default']);
    }

    /**
     * The search tool publishes its own closed schema: the same package
     * and path the window tool takes, the query, and the first line —
     * and no result count, pattern or case member to widen it.
     */
    public function test_the_search_tool_publishes_a_closed_schema_with_its_bounds(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];
        $schema = $tools[5]['inputSchema'];

        self::assertSame(OrbitronMcpApplication::SEARCH_TOOL, $tools[5]['name']);
        self::assertSame(['package', 'path', 'query'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['package', 'path', 'query', 'startLine'], array_keys($schema['properties']));
        self::assertSame(1, $schema['properties']['path']['minLength']);
        self::assertSame(256, $schema['properties']['path']['maxLength']);
        self::assertSame('string', $schema['properties']['query']['type']);
        self::assertSame(1, $schema['properties']['query']['minLength']);
        self::assertSame(256, $schema['properties']['query']['maxLength']);
        self::assertSame(1, $schema['properties']['startLine']['minimum']);
        self::assertSame(1, $schema['properties']['startLine']['default']);
    }

    /**
     * The listing tool publishes the narrowest schema of the three: the
     * package and the path, and no member that could turn one directory
     * into a recursive walk, a filter or a page.
     */
    public function test_the_listing_tool_publishes_a_closed_schema_of_a_package_and_a_path(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];
        $schema = $tools[6]['inputSchema'];

        self::assertSame(OrbitronMcpApplication::LIST_TOOL, $tools[6]['name']);
        self::assertSame(['package', 'path'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['package', 'path'], array_keys($schema['properties']));
        self::assertSame(1, $schema['properties']['package']['minLength']);
        self::assertSame(1, $schema['properties']['path']['minLength']);
        self::assertSame(256, $schema['properties']['path']['maxLength']);
        self::assertStringContainsString('200', $tools[6]['description']);
    }

    /**
     * The three installed-source tools accept any real installed
     * dependency, so their published text has to say where a name that
     * is not `kinetis/*` comes from. `orbitron_inspect` reports the
     * `kinetis/*` inventory and nothing else: a description sending an
     * agent there for every accepted name would name a discovery path
     * that cannot produce one, and a description still restricting the
     * tool to `kinetis/*` would hide the access entirely. Kinetis stays
     * first in the text; `composer.lock` is what carries the rest.
     */
    public function test_the_installed_source_tools_route_third_party_names_to_composer_lock(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];

        foreach ([4, 5, 6] as $index) {
            $name = $tools[$index]['name'];
            $package = $tools[$index]['inputSchema']['properties']['package']['description'];

            self::assertStringContainsString('composer.lock', $package, $name);
            self::assertStringContainsString('kinetis/*', $package, $name);
            self::assertStringNotContainsString(
                'one installed kinetis/* package',
                $tools[$index]['description'],
                $name,
            );
        }
    }

    /**
     * `properties` must reach the wire as an object. Decoded
     * associatively it is the same PHP value an empty list would be, so
     * only the frame itself can show which one was sent.
     */
    public function test_an_empty_properties_object_stays_an_object_on_the_wire(): void
    {
        $frame = $this->rawFrames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0];

        self::assertStringContainsString('"properties":{},"additionalProperties":false', $frame);
        self::assertStringNotContainsString('"properties":[]', $frame);
    }

    /**
     * Every reading tool says so, and the one that writes says that —
     * without claiming an idempotence a second apply does not have.
     */
    public function test_the_annotations_name_exactly_one_mutating_tool(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];
        $annotations = array_combine(array_column($tools, 'name'), array_column($tools, 'annotations'));

        $reading = [
            'orbitron_inspect',
            'orbitron_verify',
            'orbitron_scaffold_plan',
            self::TOOLS[4],
            self::TOOLS[5],
            self::TOOLS[6],
        ];

        foreach ($reading as $name) {
            self::assertSame([
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ], $annotations[$name], $name);
        }

        self::assertSame([
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ], $annotations['orbitron_scaffold_apply']);

        // The one tool that reaches the network, and the only one whose
        // annotations this package does not author.
        self::assertSame([
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ], $annotations[DocsApplication::READ_TOOL]);
    }

    public function test_the_context_resource_is_the_markdown_document_the_command_prints(): void
    {
        $list = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"resources/list"}'])[0]['result']['resources'];

        self::assertSame([
            'uri' => OrbitronMcpApplication::CONTEXT_URI,
            'name' => 'Orbitron context',
            'description' => $list[0]['description'],
            'mimeType' => 'text/markdown',
        ], $list[0]);

        $contents = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"'
            . OrbitronMcpApplication::CONTEXT_URI . '"}}',
        ])[0]['result']['contents'];

        self::assertSame(OrbitronMcpApplication::CONTEXT_URI, $contents[0]['uri']);
        self::assertSame('text/markdown', $contents[0]['mimeType']);
        self::assertSame($this->documents()->context(), $contents[0]['text']);
    }

    public function test_an_unknown_resource_uri_is_refused(): void
    {
        $error = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kinetis://orbitron/nope"}}',
        ])[0]['error'];

        self::assertSame(-32002, $error['code']);
    }

    /**
     * One connection carries both halves: Orbitron's own context, then
     * kinetis/mcp-docs' catalogue whole and in its own order. A client
     * registers no second server to reach the documentation.
     */
    public function test_the_resource_list_is_the_context_followed_by_the_whole_documentation_catalogue(): void
    {
        $resources = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"resources/list"}'])[0]['result']['resources'];

        $expected = [OrbitronMcpApplication::CONTEXT_URI];

        foreach (DocsCatalogue::pages() as $page) {
            $expected[] = $page->uri();
        }

        self::assertSame($expected, array_column($resources, 'uri'));
        self::assertContains('kinetis://docs/agent-workflow', array_column($resources, 'uri'));
        self::assertSame(
            [DocsCatalogue::MIME_TYPE],
            array_values(array_unique(array_column(array_slice($resources, 1), 'mimeType'))),
        );
    }

    /**
     * The entry point the server instructions name, read end to end: the
     * page comes back under its own URI, and the one request made is a
     * GET of the URL the catalogue derives — no origin, ref or path from
     * the message.
     */
    public function test_a_documentation_read_is_delegated_and_returns_the_fetched_page(): void
    {
        $page = "# Agent Workflow\n\nRoute the task through the matching recipe.\n";
        $this->responses = [new MockResponse($page)];

        $contents = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kinetis://docs/agent-workflow"}}',
        ])[0]['result']['contents'];

        self::assertSame('kinetis://docs/agent-workflow', $contents[0]['uri']);
        self::assertSame(DocsCatalogue::MIME_TYPE, $contents[0]['mimeType']);
        self::assertSame($page, $contents[0]['text']);
        self::assertSame(
            [['GET', DocsCatalogue::SOURCE_BASE_URL . 'agent-workflow.md']],
            $this->requests,
        );
    }

    /**
     * A URI under the documentation prefix that the catalogue does not
     * carry is refused in the same vocabulary an unknown Orbitron URI
     * gets, and nothing is fetched for it.
     */
    public function test_an_unknown_documentation_uri_is_refused_without_a_fetch(): void
    {
        $error = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kinetis://docs/not-a-page"}}',
        ])[0]['error'];

        self::assertSame(-32002, $error['code']);
        self::assertSame([], $this->requests);
    }

    /**
     * A failed fetch keeps its detail on the server's own diagnostic
     * stream — the one the binary points at stderr — and answers the
     * client with a generic error naming only the URI it asked for. The
     * source URL and the status never reach the frame.
     */
    public function test_a_failed_documentation_fetch_is_generic_on_the_wire_and_detailed_on_the_stream(): void
    {
        $this->responses = [new MockResponse('', ['http_code' => 503])];

        $frame = $this->rawFrames([
            '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kinetis://docs/index"}}',
        ])[0];

        $error = json_decode($frame, associative: true, flags: JSON_THROW_ON_ERROR)['error'];

        self::assertSame(-32603, $error['code']);
        self::assertSame('Could not read "kinetis://docs/index".', $error['message']);
        self::assertStringNotContainsString(DocsCatalogue::SOURCE_BASE_URL, $frame);
        self::assertStringNotContainsString('expected status 200', $frame);

        $diagnostic = $this->diagnostic();

        self::assertStringContainsString(DocsCatalogue::SOURCE_BASE_URL . 'index.md', $diagnostic);
        self::assertStringContainsString('expected status 200, got 503', $diagnostic);
    }

    /**
     * The documentation window reaches the wire exactly as
     * kinetis/mcp-docs authors it. Nothing about the tool — its name,
     * what it tells a model, or the schema a client validates against —
     * is restated in this package, so a client cannot be given two
     * accounts of one tool.
     */
    public function test_the_documentation_window_tool_is_published_as_the_documentation_server_authors_it(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];
        $published = $tools[7];
        $authored = DocsApplication::readTool();

        self::assertSame($authored->name, $published['name']);
        self::assertSame($authored->description, $published['description']);
        self::assertSame($authored->inputSchema, $published['inputSchema']);
    }

    /**
     * A window call is handed to the composed documentation server
     * whole: the catalogue it resolves the URI against, the URL it
     * fetches and the document it returns are all that package's own.
     */
    public function test_a_documentation_window_call_is_delegated_and_returns_the_fetched_window(): void
    {
        $this->responses = [new MockResponse("first\nsecond\nthird\n")];

        $document = $this->call(
            '{"uri":"kinetis://docs/appendix","startLine":2,"lineCount":1}',
            DocsApplication::READ_TOOL,
        );

        self::assertSame([
            'status' => 'ok',
            'uri' => 'kinetis://docs/appendix',
            'startLine' => 2,
            'endLine' => 2,
            'hasMore' => true,
            'content' => "second\n",
        ], $document);
        self::assertSame([['GET', DocsCatalogue::SOURCE_BASE_URL . 'appendix.md']], $this->requests);
    }

    /**
     * The refusal vocabulary is the documentation server's too, and
     * reaches the client as a tool that ran and refused rather than a
     * transport error. Nothing is fetched for a URI outside the
     * catalogue.
     */
    public function test_a_documentation_window_refusal_is_the_documentation_servers_own_document(): void
    {
        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . DocsApplication::READ_TOOL
            . '","arguments":{"uri":"kinetis://docs/not-a-page"}}}',
        ])[0];

        self::assertTrue($frame['result']['isError']);
        self::assertSame(['status' => 'error', 'code' => 'resource_unknown'], self::document($frame));
        self::assertSame([], $this->requests);
    }

    /**
     * The window's arguments are validated by the package that publishes
     * its schema, not re-read here: an argument outside that schema is
     * `-32602` and nothing is fetched.
     */
    public function test_a_documentation_window_argument_outside_the_schema_is_refused_before_any_fetch(): void
    {
        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . DocsApplication::READ_TOOL
            . '","arguments":{"uri":"kinetis://docs/index","ref":"main"}}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
        self::assertStringContainsString('"ref"', $frame['error']['message']);
        self::assertSame([], $this->requests);
    }

    public function test_inspect_and_verify_return_the_documents_the_commands_write(): void
    {
        $frames = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"orbitron_inspect"}}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"orbitron_verify"}}',
        ]);

        self::assertFalse($frames[0]['result']['isError']);
        self::assertSame($this->documents()->inspect()->toJson(), $frames[0]['result']['content'][0]['text']);

        self::assertFalse($frames[1]['result']['isError']);
        self::assertSame(
            $this->documents()->verify($this->project->root)->toJson(),
            $frames[1]['result']['content'][0]['text'],
        );
    }

    /**
     * A server outlives the Composer changes an agent makes while
     * building, so what it reports is the set on disk now rather than the
     * set the process started with.
     *
     * One application answers both halves: first the package is not
     * installed, and neither the inventory document nor the source tool
     * knows it; then the generated inventory is replaced, exactly as a
     * completed `composer require` replaces it, and the very next calls on
     * that same object report it and read its admitted source. A second
     * application would assert nothing here — a new object reads a new
     * inventory whether or not the old one held onto its own.
     *
     * @throws JsonException
     */
    public function test_a_completed_dependency_change_reaches_the_next_call_on_the_same_server(): void
    {
        $this->writeInventory(['kinetis/orbitron' => ['1.0.0', '/app/vendor/kinetis/orbitron']]);

        $application = new OrbitronMcpApplication($this->project->root, $this->docs());
        $calls = [
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"orbitron_inspect"}}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":{"package":"' . self::PACKAGE . '","path":"composer.json"}}}',
        ];

        $before = self::decoded(self::session($application, $calls));

        self::assertSame(
            [['name' => 'kinetis/orbitron', 'version' => '1.0.0']],
            self::document($before[0])['packages'],
        );

        self::assertTrue($before[1]['result']['isError']);
        self::assertSame(['status' => 'error', 'code' => 'package_unknown'], self::document($before[1]));

        $this->writeInventory();

        $after = self::decoded(self::session($application, $calls));
        $packages = self::document($after[0])['packages'];
        $source = self::document($after[1]);

        self::assertIsArray($packages);
        self::assertSame(
            [self::PACKAGE => '3.1.4', 'kinetis/framework' => '1.11.2', 'kinetis/orbitron' => '1.0.0'],
            array_column($packages, 'version', 'name'),
        );

        self::assertFalse($after[1]['result']['isError']);
        self::assertSame('ok', $source['status']);
        self::assertSame('3.1.4', $source['version']);
        self::assertStringContainsString('"autoload"', (string) $source['content']);
    }

    /**
     * A verification that found errors is a tool that ran and concluded,
     * so the document still comes back — carried by an MCP error result
     * rather than a transport error that would leave the codes unreadable.
     */
    public function test_a_failed_verification_is_an_error_result_still_carrying_the_document(): void
    {
        $this->project->writeManifest('{"autoload":{"psr-4":{}}}');

        $result = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"orbitron_verify"}}',
        ])[0]['result'];

        self::assertTrue($result['isError']);
        $document = json_decode($result['content'][0]['text'], true);
        self::assertSame('error', $document['status']);
        self::assertSame('path_unmapped', $document['checks'][1]['code']);
    }

    /**
     * The whole loop an agent runs: plan, apply, observe the real files,
     * and see the second apply refuse rather than overwrite.
     */
    public function test_a_plan_then_an_apply_creates_the_real_files_and_a_second_apply_refuses(): void
    {
        $before = $this->project->tree();

        $frames = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"orbitron_scaffold_plan"}}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"orbitron_scaffold_apply"}}',
            '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"orbitron_scaffold_apply"}}',
        ]);

        $plan = json_decode($frames[0]['result']['content'][0]['text'], true);
        self::assertFalse($frames[0]['result']['isError']);
        self::assertSame('preview', $plan['mode']);
        self::assertSame('ready', $plan['status']);
        self::assertSame(HealthScaffold::TARGETS, $plan['targets']);

        $applied = json_decode($frames[1]['result']['content'][0]['text'], true);
        self::assertFalse($frames[1]['result']['isError']);
        self::assertSame('apply', $applied['mode']);
        self::assertSame('created', $applied['status']);

        foreach (HealthScaffold::TARGETS as $target) {
            self::assertFileExists($this->project->path($target));
        }

        self::assertStringContainsString(
            'namespace ' . rtrim($this->project->production, '\\') . '\\Http;',
            $this->project->contents(HealthScaffold::TARGETS[0]),
        );
        self::assertStringNotContainsString('Orbitron', $this->project->contents(HealthScaffold::TARGETS[0]));

        $again = json_decode($frames[2]['result']['content'][0]['text'], true);
        self::assertTrue($frames[2]['result']['isError'], 'a second apply refuses rather than overwriting');
        self::assertSame('refused', $again['status']);
        self::assertSame(['target_exists'], $again['codes']);

        self::assertSame(
            self::sorted([...$before, 'src/Http/HealthController.php', 'tests/Http/HealthControllerTest.php']),
            self::sorted($this->project->tree()),
        );
    }

    public function test_a_plan_writes_nothing(): void
    {
        $before = $this->project->tree();

        $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"orbitron_scaffold_plan"}}']);

        self::assertSame($before, $this->project->tree());
    }

    /**
     * Every tool publishes a closed, empty schema, so an argument is a
     * call the tool has no reading of — and no message can widen the read
     * or write set by naming a path, a source body or a URL.
     */
    public function test_any_argument_at_all_is_refused_before_the_tool_runs(): void
    {
        $before = $this->project->tree();

        $frames = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"orbitron_scaffold_apply",'
            . '"arguments":{"projectRoot":"/etc"}}}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"orbitron_verify",'
            . '"arguments":{"apply":true}}}',
        ]);

        foreach ($frames as $frame) {
            self::assertSame(-32602, $frame['error']['code']);
            self::assertStringContainsString('takes no arguments', $frame['error']['message']);
        }

        self::assertSame($before, $this->project->tree(), 'a refused call must not have written anything');
    }

    public function test_an_empty_arguments_object_is_still_a_valid_call(): void
    {
        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"orbitron_inspect","arguments":{}}}',
        ])[0];

        self::assertFalse($frame['result']['isError']);
    }

    public function test_an_unknown_tool_never_reaches_orbitron(): void
    {
        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"orbitron_delete_everything"}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
    }

    /**
     * A name no tool answers is decided from the name alone, so it is
     * refused before the operation's inventory read.
     *
     * Removing the generated inventory first is what tells the two
     * orderings apart: a snapshot taken before the name is checked turns
     * this refusal into the internal error a failed inventory read
     * becomes, which tells a client its call was malformed for the wrong
     * reason.
     */
    public function test_an_unknown_tool_is_refused_before_the_inventory_is_read(): void
    {
        $application = new OrbitronMcpApplication($this->project->root, $this->docs());

        self::assertTrue(unlink($this->project->path('vendor/composer/installed.php')));

        try {
            $application->callTool('orbitron_delete_everything', new stdClass(), new ProgressEmitter(), null);
            self::fail('an unknown tool must be refused.');
        } catch (JsonRpcException $refusal) {
            self::assertSame(-32602, $refusal->rpcCode);
            self::assertSame('Unknown tool: "orbitron_delete_everything".', $refusal->getMessage());
        }
    }

    /**
     * Each call re-reads the project rather than answering from a plan it
     * kept: a layout that became unsupported between two calls is
     * reported on the second.
     */
    public function test_each_call_re_reads_the_project(): void
    {
        $first = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":'
            . '{"name":"orbitron_scaffold_plan"}}'])[0];
        self::assertFalse($first['result']['isError']);

        $this->project->writeManifest('{"autoload":{"psr-4":{}}}');

        $second = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":'
            . '{"name":"orbitron_scaffold_plan"}}'])[0];

        self::assertTrue($second['result']['isError']);
        self::assertSame(
            'path_unmapped',
            json_decode($second['result']['content'][0]['text'], true)['codes'][0],
        );
    }

    /**
     * The window a client reads: the exact lines of the exact file, the
     * installed version beside them, and no path anywhere in the frame.
     */
    public function test_a_source_read_returns_the_window_of_the_installed_file(): void
    {
        file_put_contents($this->project->path('src/Http/Controller.php'), "one\ntwo\nthree\nfour\n");

        $frame = $this->rawFrames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":{"package":"' . self::PACKAGE
            . '","path":"src/Http/Controller.php","startLine":2,"lineCount":2}}}',
        ])[0];

        $result = json_decode($frame, associative: true, flags: JSON_THROW_ON_ERROR)['result'];

        self::assertFalse($result['isError']);
        self::assertSame([
            'status' => 'ok',
            'package' => self::PACKAGE,
            'version' => '3.1.4',
            'path' => 'src/Http/Controller.php',
            'startLine' => 2,
            'endLine' => 3,
            'hasMore' => true,
            'content' => "two\nthree\n",
        ], json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR));

        self::assertStringNotContainsString($this->project->root, $frame);
    }

    /**
     * The window is optional: a call naming only the two required
     * members reads from line 1 for the published default.
     */
    public function test_the_window_defaults_to_the_first_two_hundred_lines(): void
    {
        file_put_contents($this->project->path('src/Http/Controller.php'), "one\ntwo\n");

        $document = $this->call('{"package":"' . self::PACKAGE . '","path":"src/Http/Controller.php"}');

        self::assertSame(1, $document['startLine']);
        self::assertSame(2, $document['endLine']);
        self::assertFalse($document['hasMore']);
    }

    /**
     * What a client reads back from a search: the matching lines of the
     * installed file, each without its terminator, and no path in the
     * frame.
     *
     * @throws JsonException
     */
    public function test_a_search_returns_the_matching_lines_of_the_installed_file(): void
    {
        file_put_contents(
            $this->project->path('src/Http/Controller.php'),
            "final class Controller\n{\n    public function show(): Response\n    {\n",
        );

        $frame = $this->rawFrames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SEARCH_TOOL
            . '","arguments":{"package":"' . self::PACKAGE
            . '","path":"src/Http/Controller.php","query":"public function"}}}',
        ])[0];

        $result = json_decode($frame, associative: true, flags: JSON_THROW_ON_ERROR)['result'];

        self::assertFalse($result['isError']);
        self::assertSame([
            'status' => 'ok',
            'package' => self::PACKAGE,
            'version' => '3.1.4',
            'path' => 'src/Http/Controller.php',
            'query' => 'public function',
            'startLine' => 1,
            'matches' => [['line' => 3, 'content' => '    public function show(): Response']],
            'hasMore' => false,
        ], json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR));

        self::assertStringNotContainsString($this->project->root, $frame);
    }

    /**
     * A search that finds nothing is a successful call with an empty
     * list, so a client does not read it as a failed one.
     *
     * @throws JsonException
     */
    public function test_a_search_that_matches_nothing_is_a_successful_empty_result(): void
    {
        file_put_contents($this->project->path('src/Http/Controller.php'), "one\ntwo\n");

        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SEARCH_TOOL
            . '","arguments":{"package":"' . self::PACKAGE
            . '","path":"src/Http/Controller.php","query":"three"}}}',
        ])[0];

        self::assertFalse($frame['result']['isError']);

        $document = json_decode($frame['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([], $document['matches']);
        self::assertFalse($document['hasMore']);
    }

    /**
     * A refusal is a tool that ran and concluded, so it comes back as an
     * MCP error result carrying the code — readable by the model, and
     * naming nothing about the filesystem.
     */
    public function test_a_refused_source_read_is_an_error_result_carrying_only_the_code(): void
    {
        $frame = $this->rawFrames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":{"package":"kinetis/not-installed","path":"src/A.php"}}}',
        ])[0];

        $result = json_decode($frame, associative: true, flags: JSON_THROW_ON_ERROR)['result'];

        self::assertTrue($result['isError']);
        self::assertSame(
            ['status' => 'error', 'code' => 'package_unknown'],
            json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString($this->project->root, $frame);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidArgumentsProvider(): iterable
    {
        yield 'no arguments at all' => ['{}'];
        yield 'package missing' => ['{"path":"composer.json"}'];
        yield 'path missing' => ['{"package":"kinetis/fixture"}'];
        yield 'package not a string' => ['{"package":7,"path":"composer.json"}'];
        yield 'package empty' => ['{"package":"","path":"composer.json"}'];
        yield 'path not a string' => ['{"package":"kinetis/fixture","path":["composer.json"]}'];
        yield 'path empty' => ['{"package":"kinetis/fixture","path":""}'];
        yield 'path too long' => ['{"package":"kinetis/fixture","path":"src/' . str_repeat('a', 253) . '"}'];
        yield 'startLine zero' => ['{"package":"kinetis/fixture","path":"composer.json","startLine":0}'];
        yield 'startLine negative' => ['{"package":"kinetis/fixture","path":"composer.json","startLine":-5}'];
        yield 'startLine not an integer' => ['{"package":"kinetis/fixture","path":"composer.json","startLine":"2"}'];
        yield 'startLine fractional' => ['{"package":"kinetis/fixture","path":"composer.json","startLine":1.5}'];
        yield 'lineCount zero' => ['{"package":"kinetis/fixture","path":"composer.json","lineCount":0}'];
        yield 'lineCount past the maximum' => ['{"package":"kinetis/fixture","path":"composer.json","lineCount":201}'];
        yield 'lineCount not an integer' => ['{"package":"kinetis/fixture","path":"composer.json","lineCount":null}'];
        yield 'unknown member' => ['{"package":"kinetis/fixture","path":"composer.json","encoding":"utf-8"}'];
    }

    /**
     * Every member, type, range, length and unknown key is decided in
     * the adapter, so a client that ignored the published schema still
     * cannot reach the reader with something outside it. These are
     * protocol errors, not refusal documents: the call was never one the
     * schema admits.
     */
    #[DataProvider('invalidArgumentsProvider')]
    public function test_arguments_outside_the_schema_are_invalid_params(string $arguments): void
    {
        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":' . $arguments . '}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
        self::assertArrayNotHasKey('result', $frame);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function limitLengthPathProvider(): iterable
    {
        yield 'ascii' => ['src/' . str_repeat('a', 252)];

        // 252 two-byte characters: 256 characters, 508 bytes. A length
        // check counting bytes would refuse this path, which the
        // published maxLength of 256 characters admits.
        yield 'two-byte characters' => ['src/' . str_repeat('é', 252)];

        // 252 four-byte characters, so 1012 bytes behind the same 256.
        yield 'four-byte characters' => ['src/' . str_repeat('𝍔', 252)];
    }

    /**
     * A path of exactly the published maximum reaches the reader, which
     * then reports it missing — the adapter counts the characters JSON
     * Schema counts, not the bytes they happen to occupy.
     *
     * @throws JsonException
     */
    #[DataProvider('limitLengthPathProvider')]
    public function test_a_path_at_the_character_limit_is_admitted_by_the_adapter(string $path): void
    {
        $document = $this->call((string) json_encode(
            ['package' => self::PACKAGE, 'path' => $path],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        self::assertSame('source_missing', $document['code'], 'the path reached the reader');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function overlongPathProvider(): iterable
    {
        yield 'ascii' => ['src/' . str_repeat('a', 253)];
        yield 'two-byte characters' => ['src/' . str_repeat('é', 253)];
        yield 'four-byte characters' => ['src/' . str_repeat('𝍔', 253)];
    }

    /**
     * One character past the maximum is refused in every encoding, so
     * the bound is a real one rather than a byte budget a multi-byte
     * path could slip under or be caught by early.
     *
     * @throws JsonException
     */
    #[DataProvider('overlongPathProvider')]
    public function test_a_path_one_character_past_the_limit_is_invalid_params(string $path): void
    {
        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":' . json_encode(
                ['package' => self::PACKAGE, 'path' => $path],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ) . '}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
        self::assertStringContainsString('256 characters', $frame['error']['message']);
    }

    /**
     * The order matters: validation happens before the package is looked
     * up and before anything is resolved, so an install root that is not
     * on disk cannot turn a malformed call into filesystem work.
     */
    public function test_invalid_params_are_refused_before_a_missing_install_root_is_reached(): void
    {
        $this->packageRoot = $this->project->path('gone');
        $this->writeInventory();

        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SOURCE_TOOL
            . '","arguments":{"package":"' . self::PACKAGE . '","path":"composer.json","lineCount":9999}}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
        self::assertStringContainsString('lineCount', $frame['error']['message']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSearchArgumentsProvider(): iterable
    {
        yield 'no arguments at all' => ['{}'];
        yield 'query missing' => ['{"package":"kinetis/fixture","path":"composer.json"}'];
        yield 'query empty' => ['{"package":"kinetis/fixture","path":"composer.json","query":""}'];
        yield 'query not a string' => ['{"package":"kinetis/fixture","path":"composer.json","query":7}'];
        yield 'query null' => ['{"package":"kinetis/fixture","path":"composer.json","query":null}'];
        yield 'package missing' => ['{"path":"composer.json","query":"final"}'];
        yield 'path missing' => ['{"package":"kinetis/fixture","query":"final"}'];
        yield 'path too long' => [
            '{"package":"kinetis/fixture","path":"src/' . str_repeat('a', 253) . '","query":"final"}',
        ];
        yield 'startLine zero' => ['{"package":"kinetis/fixture","path":"composer.json","query":"a","startLine":0}'];
        yield 'startLine not an integer' => [
            '{"package":"kinetis/fixture","path":"composer.json","query":"a","startLine":"2"}',
        ];

        // The window tool's member, which this schema does not name: the
        // two are validated apart, so neither admits the other's input.
        yield 'the window tool\'s lineCount' => [
            '{"package":"kinetis/fixture","path":"composer.json","query":"a","lineCount":10}',
        ];
        yield 'a result limit' => ['{"package":"kinetis/fixture","path":"composer.json","query":"a","limit":5}'];
        yield 'a case mode' => [
            '{"package":"kinetis/fixture","path":"composer.json","query":"a","caseSensitive":false}',
        ];
    }

    /**
     * The search schema is enforced here as fully as the window's, so a
     * client that ignored it still cannot reach the reader with a
     * member, a type or a bound the published schema has no reading of.
     */
    #[DataProvider('invalidSearchArgumentsProvider')]
    public function test_search_arguments_outside_the_schema_are_invalid_params(string $arguments): void
    {
        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SEARCH_TOOL
            . '","arguments":' . $arguments . '}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
        self::assertArrayNotHasKey('result', $frame);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function queryLengthProvider(): iterable
    {
        yield 'ascii at the maximum' => [str_repeat('a', 256), true];
        yield 'ascii one past it' => [str_repeat('a', 257), false];

        // 1024 bytes behind the same 256 characters: a byte count would
        // refuse a query the published maxLength admits.
        yield 'four-byte characters at the maximum' => [str_repeat('𝍔', 256), true];
        yield 'four-byte characters one past it' => [str_repeat('𝍔', 257), false];
    }

    /**
     * The query bound is counted in the characters JSON Schema counts,
     * so the same literal is admitted or refused by its length rather
     * than by the bytes its encoding happens to need.
     *
     * @throws JsonException
     */
    #[DataProvider('queryLengthProvider')]
    public function test_a_query_is_bounded_by_characters_rather_than_bytes(string $query, bool $admitted): void
    {
        file_put_contents($this->project->path('src/Http/Controller.php'), "one\n");

        $arguments = (string) json_encode(
            ['package' => self::PACKAGE, 'path' => 'src/Http/Controller.php', 'query' => $query],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        if ($admitted) {
            self::assertSame([], $this->call($arguments, OrbitronMcpApplication::SEARCH_TOOL)['matches']);

            return;
        }

        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SEARCH_TOOL
            . '","arguments":' . $arguments . '}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
        self::assertStringContainsString('"query" must be at most 256 characters', $frame['error']['message']);
    }

    /**
     * The order is the window tool's: a malformed search is refused
     * before the package is looked up, so it cannot turn into
     * filesystem work either.
     */
    public function test_invalid_search_params_are_refused_before_a_missing_install_root_is_reached(): void
    {
        $this->packageRoot = $this->project->path('gone');
        $this->writeInventory();

        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::SEARCH_TOOL
            . '","arguments":{"package":"' . self::PACKAGE . '","path":"composer.json","query":""}}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
        self::assertStringContainsString('"query" must be a non-empty string', $frame['error']['message']);
    }

    /**
     * The listing a client reads: the names and kinds of that one
     * directory's own children, the installed version beside them, and
     * no path anywhere in the frame.
     *
     * @throws JsonException
     */
    public function test_a_source_listing_returns_the_children_of_the_installed_directory(): void
    {
        file_put_contents($this->project->path('src/Http/Controller.php'), "one\n");
        file_put_contents($this->project->path('src/Http/Responder.php'), "two\n");
        self::assertTrue(mkdir($this->project->path('src/Http/Responses'), 0o700));
        file_put_contents($this->project->path('src/Http/Responses/Json.php'), "three\n");

        $frame = $this->rawFrames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::LIST_TOOL
            . '","arguments":{"package":"' . self::PACKAGE . '","path":"src/Http"}}}',
        ])[0];

        $result = json_decode($frame, associative: true, flags: JSON_THROW_ON_ERROR)['result'];

        self::assertFalse($result['isError']);
        self::assertSame([
            'status' => 'ok',
            'package' => self::PACKAGE,
            'version' => '3.1.4',
            'path' => 'src/Http',
            'entries' => [
                ['name' => 'Controller.php', 'type' => 'file'],
                ['name' => 'Responder.php', 'type' => 'file'],
                ['name' => 'Responses', 'type' => 'directory'],
            ],
        ], json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR));

        self::assertStringNotContainsString($this->project->root, $frame);
    }

    /**
     * A listing refusal is a tool that ran and concluded, like every
     * other: an MCP error result carrying the code and nothing else.
     *
     * @throws JsonException
     */
    public function test_a_refused_listing_is_an_error_result_carrying_only_the_code(): void
    {
        file_put_contents($this->project->path('src/Http/Controller.php'), "one\n");

        $frame = $this->rawFrames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::LIST_TOOL
            . '","arguments":{"package":"' . self::PACKAGE . '","path":"src/Http/Controller.php"}}}',
        ])[0];

        $result = json_decode($frame, associative: true, flags: JSON_THROW_ON_ERROR)['result'];

        self::assertTrue($result['isError']);
        self::assertSame(
            ['status' => 'error', 'code' => 'source_not_directory'],
            json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString($this->project->root, $frame);
    }

    /**
     * The package root and its two readable files are not listable, so
     * the one tool that names a directory cannot be pointed at one.
     *
     * @throws JsonException
     */
    public function test_a_root_file_is_not_listable(): void
    {
        $document = $this->call(
            '{"package":"' . self::PACKAGE . '","path":"composer.json"}',
            OrbitronMcpApplication::LIST_TOOL,
        );

        self::assertSame(['status' => 'error', 'code' => 'path_not_admitted'], $document);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidListArgumentsProvider(): iterable
    {
        yield 'no arguments at all' => ['{}'];
        yield 'package missing' => ['{"path":"src"}'];
        yield 'path missing' => ['{"package":"kinetis/fixture"}'];
        yield 'package empty' => ['{"package":"","path":"src"}'];
        yield 'path empty' => ['{"package":"kinetis/fixture","path":""}'];
        yield 'path not a string' => ['{"package":"kinetis/fixture","path":["src"]}'];
        yield 'path too long' => ['{"package":"kinetis/fixture","path":"src/' . str_repeat('a', 253) . '"}'];

        // The members the other two tools take, and the ones a listing
        // would need to become a walk: this schema names none of them.
        yield 'the window tool\'s startLine' => ['{"package":"kinetis/fixture","path":"src","startLine":1}'];
        yield 'the window tool\'s lineCount' => ['{"package":"kinetis/fixture","path":"src","lineCount":10}'];
        yield 'the search tool\'s query' => ['{"package":"kinetis/fixture","path":"src","query":"final"}'];
        yield 'a recursion flag' => ['{"package":"kinetis/fixture","path":"src","recursive":true}'];
        yield 'a depth' => ['{"package":"kinetis/fixture","path":"src","depth":2}'];
        yield 'a glob' => ['{"package":"kinetis/fixture","path":"src","pattern":"*.php"}'];
        yield 'a result limit' => ['{"package":"kinetis/fixture","path":"src","limit":10}'];
    }

    /**
     * The listing schema is enforced here as fully as the other two, so
     * a client that ignored it still cannot reach the reader with a
     * member the published schema has no reading of — least of all one
     * that would widen a directory into a tree.
     */
    #[DataProvider('invalidListArgumentsProvider')]
    public function test_listing_arguments_outside_the_schema_are_invalid_params(string $arguments): void
    {
        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . OrbitronMcpApplication::LIST_TOOL
            . '","arguments":' . $arguments . '}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
        self::assertArrayNotHasKey('result', $frame);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentToolProvider(): iterable
    {
        foreach (array_slice(self::TOOLS, 0, 4) as $name) {
            yield $name => [$name];
        }
    }

    /**
     * The three tools that take arguments must not have loosened the
     * other four: each of them still refuses any argument at all.
     */
    #[DataProvider('documentToolProvider')]
    public function test_the_document_tools_still_take_no_arguments(string $name): void
    {
        $frame = $this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . $name
            . '","arguments":{"package":"kinetis/framework"}}}',
        ])[0];

        self::assertSame(-32602, $frame['error']['code']);
        self::assertStringContainsString('takes no arguments', $frame['error']['message']);
    }

    /**
     * The document one source call concluded with.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function call(string $arguments, string $tool = OrbitronMcpApplication::SOURCE_TOOL): array
    {
        return self::document($this->frames([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . $tool
            . '","arguments":' . $arguments . '}}',
        ])[0]);
    }

    /**
     * kinetis/mcp-docs' real application, over a client that answers from
     * {@see $responses} and records what it was asked for. Only the
     * transport is a stand-in: the catalogue, the URL, the bounds and the
     * failure vocabulary are that package's own.
     */
    private function docs(): DocsApplication
    {
        $responses = $this->responses;

        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requests[] = [$method, $url];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new DocsApplication(new DocsFetcher($client), $this->diagnostics);
    }

    /** Everything the composed documentation server wrote to its stream. */
    private function diagnostic(): string
    {
        rewind($this->diagnostics);

        return (string) stream_get_contents($this->diagnostics);
    }

    /** The documents the server must have produced, from the same inventory it reads. */
    private function documents(): Documents
    {
        return new Documents(InstalledPackages::fromProject($this->project->root));
    }

    /**
     * The generated Composer inventory the server reads, in the shape
     * `Composer\InstalledVersions` documents and itself requires.
     *
     * Writing it again is what a completed Composer dependency change
     * does to a project, which is how the live-refresh test above moves
     * a package into a running server's view.
     *
     * @param array<string, array{string, string}>|null $packages name => [pretty version, install
     *        root], or null for the set the fixture project normally has
     */
    private function writeInventory(?array $packages = null): void
    {
        $versions = [];

        foreach ($packages ?? $this->installed() as $name => [$version, $root]) {
            $versions[$name] = [
                'pretty_version' => $version,
                'version' => $version . '.0',
                'type' => 'library',
                'install_path' => $root,
                'aliases' => [],
                'dev_requirement' => false,
            ];
        }

        $directory = $this->project->path('vendor/composer');

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0o700, true), "Could not create {$directory}.");
        }

        file_put_contents($directory . '/installed.php', '<?php return ' . var_export([
            'root' => [
                'name' => 'orbitron/consumer',
                'pretty_version' => 'dev-main',
                'version' => 'dev-main',
                'type' => 'project',
                'install_path' => $this->project->root . '/',
                'aliases' => [],
                'dev' => true,
            ],
            'versions' => $versions,
        ], true) . ';');
    }

    /**
     * The set the fixture project has installed: Orbitron itself, the
     * framework, and the one package the installed-source tools read.
     *
     * @return array<string, array{string, string}>
     */
    private function installed(): array
    {
        return [
            'kinetis/framework' => ['1.11.2', '/app/vendor/kinetis/framework'],
            'kinetis/orbitron' => ['1.0.0', '/app/vendor/kinetis/orbitron'],
            self::PACKAGE => ['3.1.4', $this->packageRoot],
        ];
    }

    /**
     * @param list<string> $messages
     * @return list<array<string, mixed>>
     */
    private function frames(array $messages): array
    {
        return self::decoded($this->rawFrames($messages));
    }

    /**
     * The document one frame's tool result carries.
     *
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     * @throws JsonException
     */
    private static function document(array $frame): array
    {
        self::assertArrayHasKey('result', $frame, 'the call was refused: ' . json_encode($frame));

        /** @var array<string, mixed> */
        return json_decode($frame['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $frames
     * @return list<array<string, mixed>>
     * @throws JsonException
     */
    private static function decoded(array $frames): array
    {
        $decoded = [];

        foreach ($frames as $frame) {
            $decoded[] = json_decode($frame, associative: true, flags: JSON_THROW_ON_ERROR);
        }

        /** @var list<array<string, mixed>> */
        return $decoded;
    }

    /**
     * @param list<string> $messages
     * @return list<string>
     */
    private function rawFrames(array $messages): array
    {
        return self::session(new OrbitronMcpApplication($this->project->root, $this->docs()), $messages);
    }

    /**
     * One server, one batch of messages, the frames it wrote back. The
     * application is a parameter so a test can send it two batches with a
     * dependency change between them, which is the only way a defect that
     * only a second call can show is reachable at all.
     *
     * @param list<string> $messages
     * @return list<string>
     */
    private static function session(OrbitronMcpApplication $application, array $messages): array
    {
        $input = fopen('php://memory', 'r+');
        self::assertIsResource($input);
        fwrite($input, implode("\n", $messages) . "\n");
        rewind($input);

        $output = fopen('php://memory', 'r+');
        self::assertIsResource($output);

        new StdioLoop()->run(new McpServer($application->serverInfo(), $application), $input, $output);

        rewind($output);

        return array_values(array_filter(
            explode("\n", (string) stream_get_contents($output)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private static function sorted(array $paths): array
    {
        sort($paths, SORT_STRING);

        return $paths;
    }
}
