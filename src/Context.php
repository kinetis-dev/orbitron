<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

use Kinetis\McpDocs\DocsApplication;

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
        'Orbitron ships no model and no shell; you supply the coding agent. Its MCP server exposes the same documents to an agent that speaks MCP, and adds one thing the commands do not have: the Kinetis documentation pages, as bounded line windows of one page and as `kinetis://docs/*` resources, both served by kinetis/mcp-docs from inside the same connection.',
        'This document is reference material, not evidence. It does not establish that an application preserves request isolation, non-blocking I/O, or any other invariant — the guides below state the rules, and the project\'s own tests and review are what settle compliance.',
        'The package facts below describe what is installed in this project. They say nothing about the current state of Kinetis main. The documentation pages the MCP server serves are published from main and can describe behavior newer than these versions, so the versions below and the installed source are the authority for anything version-sensitive.',
        'Orbitron is a require-dev package. No production code depends on it, and removing it changes nothing an application does.',
        'Orbitron reads Composer\'s installed-package metadata and, for verification and scaffolding, the project\'s own `composer.json` through a bounded read — no other application source, no configuration and no credentials. The two files an applied scaffold creates are everything it writes.',
        'Over MCP, Orbitron also reads one bounded line window of one real installed, non-root package\'s own source, searches one such file for a literal string, and lists the direct children of one directory of such a package: any file under that package\'s install root for the window and the search, and any directory under it — or `.` for the root itself — for the listing, all read live on every call. The install root is the whole boundary, because a package keeps its production source where its own autoload map says: at the package root for a root-mapped namespace, and under `src/`, `lib/` or a generated directory otherwise. A hidden name such as `.git` or `.env`, and the package\'s own top-level `vendor/`, are not served, and a symlink resolving onto one of those or out of the package is refused rather than followed. Any package this project really installed is reachable that way, not only `kinetis/*`, because an exact installed dependency is the authority for its own behavior; `orbitron_inspect` names the `kinetis/*` ones, and the project\'s own `composer.lock` names every other. The window and the search name one file, the listing one directory; none of the three descends into a subdirectory or searches a package. It does not read the application\'s own source, tests, configuration or credentials, and the Composer root project is not a readable package.',
        'Reading a documentation page — one window of it with ' . DocsApplication::READ_TOOL . ', or whole as a `kinetis://docs/*` resource — is the one operation that leaves this machine. kinetis/mcp-docs fetches that page over HTTPS from one fixed origin, with TLS verified, no redirect followed, a 10-second idle timeout, a 30-second deadline and a 4 MiB response cap; a fetch that fails is a generic MCP error, with the URL and the reason written to the server\'s own diagnostic stream. No message chooses the origin, the ref or the page path, and the commands reach no network at all.',
        'The scaffold builds one fixed thing: `src/Http/HealthController.php` and `tests/Http/HealthControllerTest.php`, a `GET /health` route returning `{"status":"ok"}`, and a framework test that asserts that same response on two sequential requests. There is no name, path, template or other input, it creates no directory, and it writes only when `--apply` is given.',
        'Orbitron reads no application source, so it cannot tell you in advance whether the project already routes `GET /health` somewhere else. The generated test surfaces that conflict through the framework\'s own route discovery, on the first run after the scaffold is applied.',
        'Verification answers one narrow question: whether this project\'s Composer layout is the fixed one Orbitron supports. That layout is narrower than anything Kinetis itself requires, so an error means the project is outside what Orbitron assumes — not that route, command or listener discovery is broken. It establishes nothing else either: not request isolation, not non-blocking I/O, not security, not route uniqueness, not the correctness of any application code.',
        'The MCP server reads this project\'s generated Composer inventory again for each operation that reports or uses installed package facts, so a completed `composer require` or `composer remove` is visible to the next such call without restarting or reconnecting anything. One operation reads it once, so the versions a document reports and the installed source a read opens always come from the same inventory. Every other document is re-read on each call too.',
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
        'An agent that speaks MCP can register `vendor/bin/kinetis-orbitron-mcp` instead and call the same documents as tools, with the Kinetis documentation served from that one connection — as bounded line windows through ' . DocsApplication::READ_TOOL . ', and as whole-page resources. There is no second server to configure.',
        'Route the task through Agent Workflow — over MCP, the `kinetis://docs/agent-workflow` page — then follow the matching recipe, reading each guide for the versions orbitron:inspect reports, not for main.',
        'Read a guide with ' . DocsApplication::READ_TOOL . ' from line 1 and take the next window only while the section the recipe named, or a named unknown, is still unresolved; read the whole page as a `kinetis://docs/*` resource only when the complete page is what you need. Once the recipe\'s required contracts and the installed versions are established, implement — any further read must answer a named unknown.',
        'Use the installed-source listing, literal search and bounded window only to settle exact version-sensitive facts that materially govern the change. Once a fact is established, return to the application instead of inventorying unrelated package source.',
        'Let the middleware that enforces authentication document it: route or global middleware implementing `Kinetis\\OpenApi\\SecurityDescriberInterface`, which the middleware in kinetis/auth and kinetis/auth-jwt does, publishes its own scheme and requirement into the generated OpenAPI document, through a middleware group and a thin subclass as well. Reach for `#[OpenApiSecurity]` on a controller or a route method only where no middleware states the truth — authentication performed inside the controller, or middleware that describes a requirement it does not impose on that route, which the attribute with no provider publishes as `security: []`. It changes the document alone and admits no request: a route that must answer anonymously has to be outside the middleware that rejects one. The rules are in Routing & Validation, the built-in schemes in the Authentication guides; over MCP, the `kinetis://docs/routing-validation`, `kinetis://docs/auth` and `kinetis://docs/auth-jwt` pages.',
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
            [
                'name' => 'orbitron_read_package_source',
                'effect' => 'Reads one line window of one real installed, non-root package\'s own source, at the version this project has installed — the authority when a documentation page could describe a newer release, and when an exact dependency\'s behavior is what a task turns on. `orbitron_inspect` names the `kinetis/*` packages; the project\'s own `composer.lock` names every other. Takes `package` and `path` (any file under that package\'s install root, a root-mapped class beside `composer.json` included; a hidden name and the package\'s own top-level `vendor/` are refused), plus optional `startLine` (default 1) and `lineCount` (1..200, default 200). A success reports `status`, `package`, `version`, `path`, `startLine`, `endLine`, `hasMore` and `content`; `hasMore: true` is a success, not a refusal, and the caller continues with `startLine` set to `endLine + 1`. A refusal reports only `status: error` and one of `package_unknown`, `path_not_admitted`, `source_missing`, `source_unreadable`, `source_oversize`, `source_not_text`, `line_out_of_range`. Read-only; both the inventory and the file content are read again on every call.',
            ],
            [
                'name' => 'orbitron_search_package_source',
                'effect' => 'Reports every line of one such file that contains a literal string, so a known file can be searched instead of read window by window. Takes `package` and `path` as above, a `query` of 1..256 characters matched case-sensitively, and optional `startLine` (default 1). A success reports `status`, `package`, `version`, `path`, `query`, `startLine`, `matches` and `hasMore`; each match is a `line` and the `content` of that line without its terminator, at most 50 of them. No match is a success with an empty `matches`. `hasMore: true` means a later line matches too: continue with `startLine` set to the last reported line plus one. Refusals are the same codes `orbitron_read_package_source` reports. Read-only. Use it to locate a line, then read a window around it; it searches the one file it is given, never a directory or a package.',
            ],
            [
                'name' => 'orbitron_list_package_source',
                'effect' => 'Lists the direct children of one directory of one such package — any directory under its install root, or `.` for the root itself — so a file can be found when the package is known and the path is not. Takes `package` and `path`, and nothing else: no recursion, pattern, filter or paging. A success reports `status`, `package`, `version`, `path` and `entries`; each entry is a `name` and a `type` of `file` or `directory`, in bytewise name order, and an empty directory is a success with an empty `entries`. Only children admitted both by their own name and by the target they resolve to are reported, so a hidden entry, the top-level `vendor/`, a link onto either of those, a link out of the package and an unsupported entry are absent rather than listed. A refusal reports only `status: error` and one of `package_unknown`, `path_not_admitted`, `source_missing`, `source_unreadable`, `source_not_directory`, or `directory_oversize` for more than 200 reportable children — which refuses the whole listing rather than reporting part of it. Read-only, and read live on every call.',
            ],
            [
                'name' => DocsApplication::READ_TOOL,
                'effect' => 'Reads one line window of one Kinetis documentation page — the same pages served whole as `kinetis://docs/*` resources — and is how a page is read, from line 1 onward. Takes `uri` and optional `startLine` (default 1) and `lineCount` (1..200, default 200). A success reports `status`, `uri`, `startLine`, `endLine`, `hasMore` and `content`; continue with `startLine` set to `endLine + 1`, and successive windows reconstruct the page exactly as long as it has not changed on the remote between calls — every call re-fetches it, and nothing is cached or snapshotted. A window ends at `lineCount` lines or 32768 bytes of content, whichever comes first, so read `endLine` rather than assuming it. A refusal reports only `status: error` and one of `resource_unknown`, `line_out_of_range`. Read-only, and the one tool that reaches the network: the tool, its bounds and its fetch belong to kinetis/mcp-docs, which this server composes rather than copies.',
            ],
        ],
        'resources' => [
            [
                'uri' => 'kinetis://orbitron/context',
                'effect' => 'This document, as Markdown. Read locally, from the same facts the commands print.',
            ],
            [
                'uri' => 'kinetis://docs/<page>',
                'effect' => 'One Kinetis documentation page, as Markdown. The catalogue and the bounded HTTPS fetch behind it belong to kinetis/mcp-docs, which this server composes rather than copies; `resources/list` names every page, and `kinetis://docs/agent-workflow` is the entry page.',
            ],
        ],
    ];

    private const string SERVER = 'The MCP server speaks one protocol revision and serves the same documents the '
        . 'commands print, plus the documentation catalogue. It never boots the Kinetis application and accepts no '
        . 'URL, origin, ref or command from a message. Four tools take no argument; the three that reach installed '
        . 'source, orbitron_read_package_source, orbitron_search_package_source and orbitron_list_package_source, '
        . 'take only a package name, a relative path and — for the window and the search — an optional line window '
        . 'or a literal query, each validated for presence, type, range '
        . 'and length before the package lookup; the path is then admitted against a fixed set of locations, and '
        . 'the resolved target re-admitted, before anything reaches the filesystem. The documentation window takes '
        . 'a page URI from that fixed catalogue and an optional line window, validated the same way by '
        . 'kinetis/mcp-docs, which owns that tool whole. It is a local '
        . 'process your client launches, so that process and your filesystem permissions are the trust boundary '
        . 'for everything it reads and writes here; the one thing it reaches beyond them is the fixed documentation '
        . 'origin named above.';

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
