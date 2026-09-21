<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\McpDocs\DocsApplication;
use Kinetis\Orbitron\Context;
use Kinetis\Orbitron\InstalledPackages;
use Kinetis\Orbitron\PackageFact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the context document says, and that both renderings say it.
 */
final class ContextTest extends TestCase
{
    private static function context(): Context
    {
        return new Context(new InstalledPackages([
            new PackageFact('kinetis/queue', '1.3.2', '/app/vendor/kinetis/queue'),
            new PackageFact('kinetis/orbitron', '1.0.0', '/app/vendor/kinetis/orbitron'),
            new PackageFact('kinetis/framework', '1.11.2', '/app/vendor/kinetis/framework'),
            new PackageFact('kinetis/replaced-by-framework', null, null),
            new PackageFact('psr/log', '3.0.2', '/app/vendor/psr/log'),
        ]));
    }

    public function test_it_reports_the_detected_orbitron_version_and_the_sorted_package_facts(): void
    {
        $document = self::context()->toArray();

        self::assertSame('1.0.0', $document['orbitronVersion']);
        self::assertSame(
            [
                ['name' => 'kinetis/framework', 'version' => '1.11.2'],
                ['name' => 'kinetis/orbitron', 'version' => '1.0.0'],
                ['name' => 'kinetis/queue', 'version' => '1.3.2'],
            ],
            $document['packages'],
        );
    }

    public function test_it_names_orbitron_as_a_development_harness_and_states_its_limits(): void
    {
        $document = self::context()->toArray();

        self::assertSame('Orbitron', $document['harness']['name']);
        self::assertStringContainsString('development-only', $document['harness']['role']);
        self::assertStringContainsString('coding agent', $document['harness']['role']);

        $limits = implode("\n", $document['harness']['limits']);

        self::assertStringContainsString('no model', $limits);
        self::assertStringContainsString('require-dev', $limits);
        self::assertStringContainsString('not evidence', $limits);
        // Precise rather than absolute: reading that metadata goes
        // through Composer's own installed.php, verification and
        // scaffolding read the project's own composer.json, and STDOUT
        // makes "writes nothing" false too.
        self::assertStringContainsString("Composer's installed-package metadata", $limits);
        self::assertStringContainsString('`composer.json` through a bounded read', $limits);
        self::assertStringContainsString('no configuration and no credentials', $limits);
        self::assertStringNotContainsString('reads no project file', $limits);
        // The write boundary is the two fixed files and the flag that
        // asks for them, named rather than waved at.
        self::assertStringContainsString(
            'The two files an applied scaffold creates are everything it writes',
            $limits,
        );
        self::assertStringContainsString('src/Http/HealthController.php', $limits);
        self::assertStringContainsString('tests/Http/HealthControllerTest.php', $limits);
        self::assertStringContainsString('it creates no directory', $limits);
        // What the scaffold cannot establish, stated where an agent
        // reads it rather than left to be discovered.
        self::assertStringContainsString(
            'it cannot tell you in advance whether the project already routes `GET /health`',
            $limits,
        );
        // Verification is bounded in what it claims, not only in what it
        // reads — including the claim a narrower check invites, that a
        // rejected layout is a broken application.
        self::assertStringContainsString('not route uniqueness', $limits);
        self::assertStringContainsString(
            'not that route, command or listener discovery is broken',
            $limits,
        );
    }

    /**
     * The one operation that leaves this machine is stated with its
     * bounds where an agent reading the document meets it, and the MCP
     * section names both resource families rather than the context
     * alone.
     */
    public function test_it_states_the_bounded_remote_documentation_read(): void
    {
        $document = self::context()->toArray();
        $limits = implode("\n", $document['harness']['limits']);

        self::assertStringContainsString('`kinetis://docs/*`', $limits);
        self::assertStringContainsString('over HTTPS from one fixed origin', $limits);
        self::assertStringContainsString('No message chooses the origin, the ref or the page path', $limits);
        self::assertStringContainsString('the commands reach no network at all', $limits);
        // The claim this replaced: the MCP server does reach one.
        self::assertStringNotContainsString('no HTTP client', $limits);

        self::assertSame(
            ['kinetis://orbitron/context', 'kinetis://docs/<page>'],
            array_column($document['mcp']['resources'], 'uri'),
        );
        self::assertStringContainsString(
            'kinetis://docs/agent-workflow',
            implode("\n", $document['workflow']),
        );
    }

    /**
     * The three installed-source tools are named and their
     * argument-taking contract is stated in both the tool entries and
     * the server-level claim: the document must not say every tool is
     * argument-free once three are not.
     */
    public function test_the_mcp_tools_include_the_installed_source_reader_search_and_listing(): void
    {
        $document = self::context()->toArray();

        self::assertSame(
            [
                'orbitron_inspect',
                'orbitron_verify',
                'orbitron_scaffold_plan',
                'orbitron_scaffold_apply',
                'orbitron_read_package_source',
                'orbitron_search_package_source',
                'orbitron_list_package_source',
                DocsApplication::READ_TOOL,
            ],
            array_column($document['mcp']['tools'], 'name'),
        );

        $effects = array_column($document['mcp']['tools'], 'effect', 'name');
        $sourceTool = $effects['orbitron_read_package_source'];

        self::assertStringContainsString('package', $sourceTool);
        self::assertStringContainsString('path', $sourceTool);
        self::assertStringContainsString('startLine', $sourceTool);
        self::assertStringContainsString('lineCount', $sourceTool);
        self::assertStringContainsString('package_unknown', $sourceTool);
        self::assertStringContainsString('line_out_of_range', $sourceTool);

        $searchTool = $effects['orbitron_search_package_source'];

        self::assertStringContainsString('query', $searchTool);
        self::assertStringContainsString('startLine', $searchTool);
        self::assertStringContainsString('matches', $searchTool);
        self::assertStringContainsString('hasMore', $searchTool);
        // The cursor rule, which is the whole paging contract: there is
        // no member to carry it back.
        self::assertStringContainsString('last reported line plus one', $searchTool);
        self::assertStringNotContainsString('nextStartLine', $searchTool);

        $listTool = $effects['orbitron_list_package_source'];

        self::assertStringContainsString('entries', $listTool);
        self::assertStringContainsString('directory', $listTool);
        self::assertStringContainsString('source_not_directory', $listTool);
        self::assertStringContainsString('directory_oversize', $listTool);
        // The shape a caller must not expect of it: one directory, and
        // no way to ask for the rest of a refused one.
        self::assertStringContainsString('no recursion', $listTool);
        self::assertStringNotContainsString('cursor', $listTool);

        self::assertStringContainsString('Four tools take no argument', $document['server']);
        self::assertStringContainsString('orbitron_read_package_source', $document['server']);
        self::assertStringContainsString('orbitron_search_package_source', $document['server']);
        self::assertStringContainsString('orbitron_list_package_source', $document['server']);
        self::assertStringNotContainsString('every tool takes no arguments', $document['server']);
    }

    /**
     * The documentation window is stated as what it is: a page read with
     * its own continuation rule and refusal codes, owned by
     * kinetis/mcp-docs, and the one tool that leaves this machine. The
     * document must not go on claiming that only a resource read does.
     */
    public function test_the_mcp_tools_include_the_documentation_window(): void
    {
        $document = self::context()->toArray();
        $effects = array_column($document['mcp']['tools'], 'effect', 'name');
        $window = $effects[DocsApplication::READ_TOOL];

        self::assertStringContainsString('uri', $window);
        self::assertStringContainsString('startLine', $window);
        self::assertStringContainsString('lineCount', $window);
        self::assertStringContainsString('endLine + 1', $window);
        self::assertStringContainsString('resource_unknown', $window);
        self::assertStringContainsString('line_out_of_range', $window);
        self::assertStringContainsString('kinetis/mcp-docs', $window);

        $limits = implode("\n", $document['harness']['limits']);

        self::assertStringContainsString(DocsApplication::READ_TOOL, $limits);
        self::assertStringNotContainsString(
            'Reading a `kinetis://docs/*` resource is the one operation',
            $limits,
        );
    }

    public function test_it_links_to_the_authoritative_kinetis_guides(): void
    {
        $urls = array_column(self::context()->toArray()['guides'], 'url', 'title');

        self::assertSame('https://kinetis.dev/docs/agent-workflow.html', $urls['Agent Workflow']);
        self::assertSame('https://kinetis.dev/docs/application-recipes.html', $urls['Application Recipes']);
        self::assertSame('https://kinetis.dev/docs/agent-correctness.html', $urls['Agent Correctness Review']);
        self::assertSame('https://kinetis.dev/docs/orbitron.html', $urls['Orbitron']);
    }

    public function test_the_workflow_is_the_orbitron_commands_and_then_the_guides(): void
    {
        $workflow = implode("\n", self::context()->toArray()['workflow']);

        self::assertStringContainsString('orbitron:context', $workflow);
        self::assertStringContainsString('orbitron:inspect', $workflow);
        self::assertStringContainsString('orbitron:verify', $workflow);
        self::assertStringContainsString('orbitron:scaffold', $workflow);
        self::assertStringContainsString('Agent Workflow', $workflow);
        self::assertStringContainsString('Agent Correctness Review', $workflow);
    }

    /**
     * An agent that runs the suite twice at once against one database or
     * broker reads the resulting interference as a defect in the code it
     * just changed. The rule that prevents it belongs in the workflow
     * the agent reads, as a requirement rather than a preference.
     */
    public function test_the_workflow_requires_shared_state_runs_to_be_serialized(): void
    {
        $workflow = implode("\n", self::context()->toArray()['workflow']);

        self::assertStringContainsString('shared database, broker or object store one at a time', $workflow);
        self::assertStringContainsString('never two overlapping runs against the same state', $workflow);
        self::assertStringContainsString('reports failures the code does not have', $workflow);
    }

    /**
     * The document every agent reads once per task owns the stop
     * decision: bounded windows answer named unknowns, then implementation
     * begins once the recipe's contracts and versions are established.
     */
    public function test_the_workflow_reads_documentation_in_bounded_windows_and_names_when_to_stop(): void
    {
        $workflow = implode("\n", self::context()->toArray()['workflow']);

        self::assertStringContainsString(DocsApplication::READ_TOOL . ' from line 1', $workflow);
        self::assertStringContainsString(
            'take the next window only while the section the recipe named, or a named unknown, is still unresolved',
            $workflow,
        );
        self::assertStringContainsString('only when the complete page is what you need', $workflow);
        self::assertStringContainsString(
            'Once the recipe\'s required contracts and the installed versions are established, implement',
            $workflow,
        );
        self::assertStringContainsString('any further read must answer a named unknown', $workflow);
        self::assertStringNotContainsString('too long to take whole', $workflow);
    }

    public function test_the_workflow_bounds_installed_source_reading_to_material_facts(): void
    {
        $workflow = implode("\n", self::context()->toArray()['workflow']);

        self::assertStringContainsString('exact version-sensitive facts that materially govern the change', $workflow);
        self::assertStringContainsString('return to the application', $workflow);
        self::assertStringContainsString('instead of inventorying unrelated package source', $workflow);
    }

    /**
     * The document's shape is what an MCP client and any other consumer
     * parse. Prose is added to a list the schema already has; a new
     * top-level key would be a breaking change to that contract.
     */
    public function test_the_document_keys_are_the_published_schema(): void
    {
        self::assertSame(
            [
                'orbitronVersion',
                'harness',
                'guides',
                'workflow',
                'commands',
                'mcp',
                'server',
                'launcher',
                'packages',
            ],
            array_keys(self::context()->toArray()),
        );
    }

    public function test_each_command_entry_states_what_it_may_change(): void
    {
        $commands = self::context()->toArray()['commands'];

        self::assertSame(
            ['orbitron:context', 'orbitron:inspect', 'orbitron:verify', 'orbitron:scaffold'],
            array_column($commands, 'name'),
        );
        self::assertSame(
            [['markdown', 'json'], ['json'], ['json'], ['json']],
            array_column($commands, 'formats'),
        );

        foreach ($commands as $command) {
            self::assertStringContainsString('STDOUT', $command['effect']);
            self::assertStringContainsString('changes', $command['effect']);
        }
    }

    /**
     * Orbitron changes nothing, and the document says so without
     * claiming the invocation as a whole is free of side effects.
     */
    public function test_it_states_the_launcher_side_effects_orbitron_does_not_prevent(): void
    {
        $launcher = self::context()->toArray()['launcher'];

        self::assertStringContainsString('.env', $launcher);
        self::assertStringContainsString('.kinetis-cache/compiled.php', $launcher);
        self::assertStringContainsString('bootstrap: false', $launcher);
        self::assertStringContainsString('not side-effect-free', $launcher);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function factProvider(): iterable
    {
        yield 'orbitron version' => ['1.0.0'];
        yield 'framework fact' => ['kinetis/framework'];
        yield 'framework version' => ['1.11.2'];
        yield 'queue fact' => ['kinetis/queue'];
        yield 'agent workflow link' => ['https://kinetis.dev/docs/agent-workflow.html'];
        yield 'launcher cache claim' => ['.kinetis-cache/compiled.php'];
        yield 'scaffold command' => ['orbitron:scaffold'];
        yield 'scaffold controller target' => ['src/Http/HealthController.php'];
        yield 'documentation entry resource' => ['kinetis://docs/agent-workflow'];
        yield 'installed source tool' => ['orbitron_read_package_source'];
        yield 'installed source search tool' => ['orbitron_search_package_source'];
        yield 'serialized shared-state rule' => ['shared database, broker or object store one at a time'];
    }

    /**
     * Markdown renders the array the JSON format encodes, so a fact
     * present in one is present in the other.
     */
    #[DataProvider('factProvider')]
    public function test_markdown_carries_the_same_facts_as_the_document_array(string $needle): void
    {
        $context = self::context();

        self::assertStringContainsString($needle, $context->toMarkdown());
        self::assertStringContainsString(
            $needle,
            json_encode($context->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_markdown_excludes_what_the_document_array_excludes(): void
    {
        $markdown = self::context()->toMarkdown();

        self::assertStringNotContainsString('psr/log', $markdown);
        self::assertStringNotContainsString('kinetis/replaced-by-framework', $markdown);
        self::assertStringNotContainsString('/app/vendor', $markdown);
    }

    public function test_markdown_is_one_document_ending_in_a_single_newline(): void
    {
        $markdown = self::context()->toMarkdown();

        self::assertStringStartsWith('# Orbitron 1.0.0', $markdown);
        self::assertStringEndsWith("- `kinetis/queue` 1.3.2\n", $markdown);
    }
}
