<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use JsonException;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\StdioLoop;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\HealthScaffold;
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\Mcp\OrbitronMcpApplication;
use Kinetis\Orbitron\PackageFact;
use PHPUnit\Framework\TestCase;

/**
 * Orbitron over MCP, driven through the shared stdio loop so what is
 * asserted is the frames a client reads.
 *
 * The documents themselves are proved by the command suites; what belongs
 * here is that MCP reaches the same ones without running a command, that
 * the mutating tool is labelled and behaves as labelled, and that no
 * message can widen what the server touches.
 */
final class OrbitronMcpApplicationTest extends TestCase
{
    private ScaffoldProject $project;

    /**
     * @throws JsonException
     */
    protected function setUp(): void
    {
        $this->project = new ScaffoldProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
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

    public function test_the_four_tools_are_published_with_closed_empty_schemas(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];

        self::assertSame(
            ['orbitron_inspect', 'orbitron_verify', 'orbitron_scaffold_plan', 'orbitron_scaffold_apply'],
            array_column($tools, 'name'),
        );

        foreach ($tools as $tool) {
            self::assertSame(
                ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                $tool['inputSchema'],
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
     * The three reading tools say so, and the one that writes says that —
     * without claiming an idempotence a second apply does not have.
     */
    public function test_the_annotations_name_exactly_one_mutating_tool(): void
    {
        $tools = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'])[0]['result']['tools'];
        $annotations = array_combine(array_column($tools, 'name'), array_column($tools, 'annotations'));

        foreach (['orbitron_inspect', 'orbitron_verify', 'orbitron_scaffold_plan'] as $name) {
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
    }

    public function test_the_context_resource_is_the_markdown_document_the_command_prints(): void
    {
        $list = $this->frames(['{"jsonrpc":"2.0","id":1,"method":"resources/list"}'])[0]['result']['resources'];

        self::assertSame([[
            'uri' => OrbitronMcpApplication::CONTEXT_URI,
            'name' => 'Orbitron context',
            'description' => $list[0]['description'],
            'mimeType' => 'text/markdown',
        ]], $list);

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

    private function documents(): Documents
    {
        return new Documents(new InstalledPackages([
            new PackageFact('kinetis/orbitron', '1.0.0', '/app/vendor/kinetis/orbitron'),
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
        ]));
    }

    /**
     * @param list<string> $messages
     * @return list<array<string, mixed>>
     */
    private function frames(array $messages): array
    {
        $decoded = [];

        foreach ($this->rawFrames($messages) as $frame) {
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
        $input = fopen('php://memory', 'r+');
        self::assertIsResource($input);
        fwrite($input, implode("\n", $messages) . "\n");
        rewind($input);

        $output = fopen('php://memory', 'r+');
        self::assertIsResource($output);

        $application = new OrbitronMcpApplication($this->project->root, $this->documents());

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
