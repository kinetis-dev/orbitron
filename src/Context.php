<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * The Orbitron context document: what Orbitron is, what it does not do,
 * where the authoritative Kinetis guidance lives, the command workflow,
 * what each command may and may not change, and the installed
 * `kinetis/*` package facts.
 *
 * toArray() is the document; toMarkdown() renders that same array, so
 * the two output formats cannot state different things. The prose points
 * at the guides rather than reprinting them, and it claims nothing about
 * an application's correctness.
 */
final readonly class Context
{
    private const string DOCS = 'https://kinetis.dev/docs/';

    /** @var list<string> */
    private const array LIMITS = [
        'Orbitron ships no model and no shell; you supply the coding agent. Its MCP server exposes the same documents to an agent that speaks MCP, and adds one thing the commands do not have: the Kinetis documentation pages, as `kinetis://docs/*` resources served by kinetis/mcp-docs from inside the same connection.',
        'This document is reference material, not evidence. It does not establish that an application preserves request isolation, non-blocking I/O, or any other invariant — the guides below state the rules, and the project\'s own tests and review are what settle compliance.',
        'The package facts below describe what is installed in this project. They say nothing about the current state of Kinetis main. The documentation pages the MCP server serves are published from main and can describe behavior newer than these versions, so the versions below and the installed source are the authority for anything version-sensitive.',
        'Orbitron is a require-dev package. No production code depends on it, and removing it changes nothing an application does.',
        'Orbitron reads Composer\'s installed-package metadata and, for verification and scaffolding, the project\'s own `composer.json` through a bounded read — no other application source, no configuration and no credentials. The two files an applied scaffold creates are everything it writes.',
        'Reading a `kinetis://docs/*` resource is the one operation that leaves this machine. kinetis/mcp-docs fetches that page over HTTPS from one fixed origin, with TLS verified, no redirect followed, a 10-second idle timeout, a 30-second deadline and a 4 MiB response cap; a fetch that fails is a generic MCP error, with the URL and the reason written to the server\'s own diagnostic stream. No message chooses the origin, the ref or the page path, and the commands reach no network at all.',
        'The scaffold builds one fixed thing: `src/Http/HealthController.php` and `tests/Http/HealthControllerTest.php`, a `GET /health` route returning `{"status":"ok"}`, and a framework test that asserts that same response on two sequential requests. There is no name, path, template or other input, it creates no directory, and it writes only when `--apply` is given.',
        'Orbitron reads no application source, so it cannot tell you in advance whether the project already routes `GET /health` somewhere else. The generated test surfaces that conflict through the framework\'s own route discovery, on the first run after the scaffold is applied.',
        'Verification answers one narrow question: whether this project\'s Composer layout is the fixed one Orbitron supports. That layout is narrower than anything Kinetis itself requires, so an error means the project is outside what Orbitron assumes — not that route, command or listener discovery is broken. It establishes nothing else either: not request isolation, not non-blocking I/O, not security, not route uniqueness, not the correctness of any application code.',
        'The MCP server reads Composer\'s installed-package inventory once at startup, because that inventory is process-cached. Restart it after installing or removing a dependency; every other document is re-read on each call.',
    ];

    /** @var list<array{title: string, url: string}> */
    private const array GUIDES = [
        ['title' => 'Agent Workflow', 'url' => self::DOCS . 'agent-workflow.html'],
        ['title' => 'Application Recipes', 'url' => self::DOCS . 'application-recipes.html'],
        ['title' => 'Agent Correctness Review', 'url' => self::DOCS . 'agent-correctness.html'],
        ['title' => 'Reference', 'url' => self::DOCS . 'reference.html'],
        ['title' => 'Orbitron', 'url' => self::DOCS . 'orbitron.html'],
    ];

    /** @var list<string> */
    private const array WORKFLOW = [
        'Run `vendor/bin/kinetis orbitron:context` once per task to read this document.',
        'Run `vendor/bin/kinetis orbitron:inspect` to read the installed Kinetis packages and their versions as JSON.',
        'Run `vendor/bin/kinetis orbitron:verify` to read whether this project\'s Composer layout is the one Orbitron supports; exit 3 means the document reports an error.',
        'Run `vendor/bin/kinetis orbitron:scaffold` to read the health-endpoint scaffold plan, and add `--apply` to create its two files; exit 3 means the document reports a refusal or a failed write.',
        'An agent that speaks MCP can register `vendor/bin/kinetis-orbitron-mcp` instead and call the same documents as tools, with the Kinetis documentation served as resources from that one connection — there is no second server to configure.',
        'Route the task through Agent Workflow — over MCP, read the `kinetis://docs/agent-workflow` resource — then follow the matching recipe, reading each guide for the versions orbitron:inspect reports, not for main.',
        'Before calling the change done, work through Agent Correctness Review and run the project\'s own test suite.',
        'Run any test or command that mutates a shared database, broker or object store one at a time, never two overlapping runs against the same state: concurrent mutation breaks test isolation and reports failures the code does not have.',
    ];

    /** @var list<array{name: string, formats: list<string>, effect: string}> */
    private const array COMMANDS = [
        [
            'name' => 'orbitron:context',
            'formats' => ['markdown', 'json'],
            'effect' => 'Renders this document to STDOUT. Reads Composer\'s installed-package records and nothing else: writes no file, changes no cache, opens no socket, starts no process.',
        ],
        [
            'name' => 'orbitron:inspect',
            'formats' => ['json'],
            'effect' => 'Renders the installed kinetis/* inventory to STDOUT, under the same boundary: reads Composer\'s installed-package records and changes nothing.',
        ],
        [
            'name' => 'orbitron:verify',
            'formats' => ['json'],
            'effect' => 'Renders the project-layout verification to STDOUT. Reads Composer\'s installed-package records and the project\'s own composer.json through a bounded read, and changes nothing else: writes no file, opens no socket, starts no process. Exits 3 when the verification completed and the document reports an error.',
        ],
        [
            'name' => 'orbitron:scaffold',
            'formats' => ['json'],
            'effect' => 'Renders the health-endpoint scaffold plan to STDOUT. Without --apply it reads the project\'s composer.json, the four fixed directories and both targets, and changes nothing. With --apply it re-reads all of that and then creates exactly the two files it names, each through an exclusive create that never overwrites; if the second one cannot be finished, every file this invocation created is removed. It creates no directory, opens no socket and starts no process. Exits 3 when the completed operation refused or failed.',
        ],
    ];

    /** @var array{binary: string, protocolVersion: string, tools: list<array{name: string, effect: string}>, resources: list<array{uri: string, effect: string}>} */
    private const array MCP = [
        'binary' => 'vendor/bin/kinetis-orbitron-mcp',
        'protocolVersion' => '2025-06-18',
        'tools' => [
            [
                'name' => 'orbitron_inspect',
                'effect' => 'Returns the same document as `orbitron:inspect`. Read-only.',
            ],
            [
                'name' => 'orbitron_verify',
                'effect' => 'Returns the same document as `orbitron:verify`. Read-only; an error document comes back as an MCP error result.',
            ],
            [
                'name' => 'orbitron_scaffold_plan',
                'effect' => 'Returns the same document as `orbitron:scaffold` without `--apply`. Read-only.',
            ],
            [
                'name' => 'orbitron_scaffold_apply',
                'effect' => 'Returns the same document as `orbitron:scaffold --apply`, and creates the two files. This is the only tool that writes; selecting it is the whole mutation request, so it takes no argument, and your MCP client\'s configured approval policy controls whether it runs.',
            ],
        ],
        'resources' => [
            [
                'uri' => 'kinetis://orbitron/context',
                'effect' => 'This document, as Markdown. Read locally, from the same facts the commands print.',
            ],
            [
                'uri' => 'kinetis://docs/<page>',
                'effect' => 'One Kinetis documentation page, as Markdown. The catalogue and the bounded HTTPS fetch behind it belong to kinetis/mcp-docs, which this server composes rather than copies; `resources/list` names every page. Start at `kinetis://docs/agent-workflow`.',
            ],
        ],
    ];

    private const string SERVER = 'The MCP server speaks one protocol revision and serves the same documents the '
        . 'commands print, plus the documentation catalogue. It never boots the Kinetis application, accepts no path, '
        . 'source, URL, origin, ref or command from a message, and every tool takes no arguments at all. It is a '
        . 'local process your client launches, so that process and your filesystem permissions are the trust '
        . 'boundary for everything it reads and writes here; the one thing it reaches beyond them is the fixed '
        . 'documentation origin named above.';

    private const string LAUNCHER = 'An invocation changes more than the command itself does, and the rest is not '
        . 'side-effect-free. `vendor/bin/kinetis` loads `.env` before it dispatches any command, and under '
        . 'APP_ENV=production it compiles `.kinetis-cache/compiled.php` when no valid artifact is present. Both belong '
        . 'to the framework launcher, and `bootstrap: false` does not prevent either.';

    public function __construct(
        private InstalledPackages $packages,
    ) {}

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
    public function toArray(): array
    {
        return [
            'orbitronVersion' => $this->packages->orbitronVersion(),
            'harness' => [
                'name' => 'Orbitron',
                'role' => 'A development-only construction harness for Kinetis applications. It gives any shell-capable '
                    . 'coding agent portable Kinetis context, a stable installed-package inventory, a deterministic '
                    . 'layout verification and one previewable health-endpoint scaffold, reachable from a shell or '
                    . 'through its own MCP server.',
                'limits' => self::LIMITS,
            ],
            'guides' => self::GUIDES,
            'workflow' => self::WORKFLOW,
            'commands' => self::COMMANDS,
            'mcp' => self::MCP,
            'server' => self::SERVER,
            'launcher' => self::LAUNCHER,
            'packages' => $this->packages->records(),
        ];
    }

    public function toMarkdown(): string
    {
        $document = $this->toArray();
        $harness = $document['harness'];

        $lines = [
            "# {$harness['name']} {$document['orbitronVersion']}",
            '',
            $harness['role'],
            '',
            '## Limits',
            '',
        ];

        foreach ($harness['limits'] as $limit) {
            $lines[] = "- {$limit}";
        }

        $lines[] = '';
        $lines[] = '## Authoritative guidance';
        $lines[] = '';

        foreach ($document['guides'] as $guide) {
            $lines[] = "- [{$guide['title']}]({$guide['url']})";
        }

        $lines[] = '';
        $lines[] = '## Workflow';
        $lines[] = '';

        foreach ($document['workflow'] as $index => $step) {
            $lines[] = ($index + 1) . ". {$step}";
        }

        $lines[] = '';
        $lines[] = '## Commands';
        $lines[] = '';

        foreach ($document['commands'] as $command) {
            $lines[] = "- `{$command['name']} --format=" . implode('|', $command['formats']) . "` — {$command['effect']}";
        }

        $lines[] = '';
        $lines[] = '## MCP server';
        $lines[] = '';
        $lines[] = "Binary: `{$document['mcp']['binary']}` — MCP {$document['mcp']['protocolVersion']}.";
        $lines[] = '';

        foreach ($document['mcp']['tools'] as $tool) {
            $lines[] = "- `{$tool['name']}` — {$tool['effect']}";
        }

        foreach ($document['mcp']['resources'] as $resource) {
            $lines[] = "- Resource `{$resource['uri']}` — {$resource['effect']}";
        }

        $lines[] = '';
        $lines[] = $document['server'];
        $lines[] = '';
        $lines[] = '## Launcher';
        $lines[] = '';
        $lines[] = $document['launcher'];
        $lines[] = '';
        $lines[] = '## Installed Kinetis packages';
        $lines[] = '';

        foreach ($document['packages'] as $package) {
            $lines[] = "- `{$package['name']}` {$package['version']}";
        }

        return implode("\n", $lines) . "\n";
    }
}
