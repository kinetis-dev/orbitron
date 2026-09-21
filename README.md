<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/orbitron</strong>
  <br>
  <strong>A development-only application construction harness for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/orbitron"><img src="https://img.shields.io/packagist/v/kinetis/orbitron?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/orbitron"><img src="https://img.shields.io/packagist/dt/kinetis/orbitron" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/orbitron"><img src="https://img.shields.io/packagist/php-v/kinetis/orbitron" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/orbitron"><img src="https://img.shields.io/packagist/l/kinetis/orbitron" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Kinetis does not hand you a generic dashboard or force your application
into a prebuilt scaffold. Orbitron equips the AI coding agent you already
use with Kinetis context, project inspection, verification and controlled
scaffolding, so it builds against the packages and versions actually
installed.

You bring the agent: Orbitron contains no model and talks to none, and it
generates no application of its own. What it gives the agent is four
documents — a portable Kinetis context document, the project's installed
`kinetis/*` package inventory as JSON, a deterministic verification of the
project's Composer layout, and one health-endpoint scaffold you preview
before you apply it. Only applying the scaffold changes anything, and what
it changes is two fixed files. None of the four is evidence that
application code is correct.

Over MCP it also serves the Kinetis documentation and three more tools
that reach an installed package's own source — one reads a bounded line
window of a file, one searches one file for a literal string, and one
lists the direct children of one directory, which is how a file is found
when the package is known and the path is not. That is the exact source
this project has installed, when a guide published from main could
describe a newer release. Reach for a `kinetis/*` package first; any
other dependency this project installed is readable the same way, for
the times an exact library's behavior is what a decision turns on.

```console
composer require --dev kinetis/orbitron
```

Reach them either way: four commands on `vendor/bin/kinetis`, each
writing one document to STDOUT, or the stdio MCP server
`vendor/bin/kinetis-orbitron-mcp`, which serves the same documents as
tools, the installed-source tools, and the documentation as resources.
Orbitron has no model of its own and no shell. The commands reach no
network; reading a documentation resource over MCP is the one thing that
does, and [it is bounded below](#over-mcp).

## `orbitron:context`

```console
vendor/bin/kinetis orbitron:context
vendor/bin/kinetis orbitron:context --format=json
```

One document: what Orbitron is and what it does not establish, links to
the authoritative Kinetis guides, the command workflow, what each
command may change, the launcher behavior below, and the installed
`kinetis/*` package facts. `--format` accepts `markdown` (the default)
and `json`; both render the same document from the same facts.

The workflow it prints ends with the rule that keeps an agent's evidence
honest: run any test or command that mutates a shared database, broker
or object store one at a time. Two overlapping runs mutate the same
state and report failures the code does not have.

## `orbitron:inspect`

```console
vendor/bin/kinetis orbitron:inspect
```

JSON only — this document exists to be parsed. Omitting `--format` and
writing `--format=json` are the same invocation.

```json
{
    "schemaVersion": 1,
    "orbitronVersion": "1.1.0",
    "packages": [
        {
            "name": "kinetis/framework",
            "version": "1.12.0"
        },
        {
            "name": "kinetis/mcp-docs",
            "version": "1.4.0"
        },
        {
            "name": "kinetis/mcp-protocol",
            "version": "1.0.0"
        },
        {
            "name": "kinetis/orbitron",
            "version": "1.1.0"
        }
    ]
}
```

`packages` carries every installed package under the `kinetis/` vendor,
ordered by name, one entry per name. A name that Composer only lists
because an installed package *replaces* or *provides* it has no version
and no install path, and is not reported. Install paths are read to make
that distinction and never appear in the output.

The Composer root project is not reported either. Composer lists it among
the installed packages, but it is the project being developed rather than
something the project installed, so a root under the `kinetis/` vendor —
`kinetis/skeleton`, or `kinetis/orbitron` itself while this package is
developed — is left out of `packages`. `orbitronVersion` still reads it,
which is how Orbitron names its version when it is the root.

`orbitronVersion` is the detected `kinetis/orbitron` package version, so
it cannot disagree with what is installed; in the Kinetis monorepo that
is `dev-main`.

## `orbitron:verify`

```console
vendor/bin/kinetis orbitron:verify
```

JSON only, like `orbitron:inspect`. One deterministic document, with a
fixed key and check order:

```json
{
    "schemaVersion": 1,
    "orbitronVersion": "1.1.0",
    "status": "pass",
    "checks": [
        {
            "name": "composerManifest",
            "state": "pass",
            "code": "manifest_read"
        },
        {
            "name": "productionNamespace",
            "state": "pass",
            "code": "namespace_unique"
        },
        {
            "name": "testNamespace",
            "state": "pass",
            "code": "namespace_unique"
        }
    ],
    "namespaces": {
        "production": "App\\",
        "test": "App\\Tests\\"
    }
}
```

`schemaVersion` is `1` and moves only when the document's shape changes.
`status` is `error` when any check failed and `pass` otherwise. `code` is
the stable machine value to branch on; it names an outcome and never
echoes the manifest, an exception message, or a path. `namespaces` is
present only when the whole layout is the admitted one — a
half-recognized project reports `null`.

### The layout it admits

- exactly one `autoload.psr-4` prefix whose mapping is the single string
  `"src/"`;
- exactly one `autoload-dev.psr-4` prefix whose mapping is the single
  string `"tests/"`;
- each prefix a non-empty PSR-4 namespace ending in `\`.

Any other mapping in either map is left alone, array-valued ones
included: a project declares whatever else it needs. Two prefixes
pointing at the same fixed path, or an array-valued mapping that reaches
it, are rejected rather than guessed at.

| Check | State | Code |
|---|---|---|
| `composerManifest` | `pass` | `manifest_read` |
| `composerManifest` | `error` | `manifest_missing`, `manifest_unreadable`, `manifest_oversize`, `manifest_not_json`, `manifest_not_an_object` |
| `productionNamespace`, `testNamespace` | `pass` | `namespace_unique` |
| `productionNamespace`, `testNamespace` | `error` | `psr4_map_missing`, `path_unmapped`, `path_ambiguous`, `path_mapping_not_a_string`, `namespace_invalid` |
| `productionNamespace`, `testNamespace` | `skip` | `manifest_unusable` |

A manifest that could not be used makes both namespace checks `skip`,
not `error`: the layout was never seen, so nothing is claimed about it.

### What it proves, and what it does not

It proves that this project's Composer layout is the narrow one Orbitron
supports. That layout is a deliberate prerequisite for the scaffolding
built on it — a fixed pair of namespaces is what lets a generated class
be placed without guessing — and it is narrower than anything Kinetis
itself demands.

Discovery asks for much less: `Kinetis\Cache\NamespaceScanner` walks
every string prefix under `autoload.psr-4`, at any directory, accepting
array-valued mappings, and it never reads `autoload-dev` at all. An
`error` here therefore does not mean discovery is broken — a project
with three production prefixes, or with its tests under a path this
rejects, still has every route, command, tool and listener discovered as
usual.

One real discovery failure is inside what the production check catches,
though: a project with no usable `autoload.psr-4` map has no root to
scan, so discovery finds nothing and says so through a single
`error_log()` line. That project reports `psr4_map_missing` or
`path_unmapped` here instead.

It proves nothing else. It is not evidence about request isolation,
non-blocking I/O, security, route uniqueness, or the correctness of any
application code. The project's own tests and review settle those.

The only project file it reads is `<project root>/composer.json`, found
from the root the framework's own `Kinetis\Runtime\ProjectRoot::detect()`
reports — never a path passed on the command line. It is read through a
bounded stream read of at most 1 MiB, plus the single byte that tells an
admitted manifest from an oversized one; a larger file is refused
without ever being read whole.

## `orbitron:scaffold`

```console
vendor/bin/kinetis orbitron:scaffold
vendor/bin/kinetis orbitron:scaffold --apply
```

JSON only, like the two commands above. Without `--apply` it is a
preview: every precondition is read and the plan is written, with
nothing touched on disk.

```json
{
    "schemaVersion": 1,
    "orbitronVersion": "1.1.0",
    "mode": "preview",
    "status": "ready",
    "codes": [
        "scaffold_ready"
    ],
    "targets": [
        "src/Http/HealthController.php",
        "tests/Http/HealthControllerTest.php"
    ],
    "remainingFiles": []
}
```

`mode` is `preview` or `apply`. `status` is `ready` (the preview holds),
`created` (both files exist), `refused` (a precondition the project does
not meet, decided before anything was opened) or `failed` (a write that
started and did not finish). `codes` are the stable machine values to
branch on, in a fixed order. `targets` is the complete write set, always
both paths and always in this order. `remainingFiles` is empty unless a
rollback could not put the project back.

### What it builds

Exactly two files, and nothing about them is configurable — there is no
name, path, template, source-body or plugin input:

- `src/Http/HealthController.php`, under the production namespace the
  layout check found, declaring `#[Get('/health')]` and returning
  `['status' => 'ok']`. The framework encodes that array as
  `{"status":"ok"}` with the route's status, the same as any other array
  a controller returns.
- `tests/Http/HealthControllerTest.php`, under the test namespace,
  extending `Kinetis\Testing\ApplicationTestCase` and issuing two
  sequential `GET /health` requests against one booted application,
  asserting the same success response both times.

Neither generated file mentions Orbitron. They import the framework and
the framework's testing API only, so removing Orbitron leaves them
working.

### What it requires

- the admitted layout above, with both namespaces valid;
- `src`, `src/Http`, `tests` and `tests/Http` already present as real
  directories, none of them a symlink, each resolving inside the project
  root;
- neither target occupied — a regular file, a directory, a symlink, or a
  symlink pointing at nothing all count as occupied.

Orbitron creates no directory. A project missing `src/Http` is refused,
not filled in.

| Status | Code | Meaning |
|---|---|---|
| `ready` | `scaffold_ready` | The preview holds: both files can be created. |
| `created` | `scaffold_created` | Both files exist, every byte written, flushed and closed. |
| `refused` | `manifest_missing`, `manifest_unreadable`, `manifest_oversize`, `manifest_not_json`, `manifest_not_an_object`, `manifest_unusable`, `psr4_map_missing`, `path_unmapped`, `path_ambiguous`, `path_mapping_not_a_string`, `namespace_invalid` | The layout is not the admitted one; these are `orbitron:verify`'s own codes, from the same reader. |
| `refused` | `directory_missing` | One of the four fixed directories is absent or is not a directory. |
| `refused` | `directory_outside_project` | One of them resolves outside the project root. |
| `refused` | `directory_symlinked` | One of them is a symlink. |
| `refused` | `target_exists` | One of the two targets is occupied. |
| `failed` | `write_failed` + `rolled_back` | A file could not be created or written; everything this invocation created was removed. |
| `failed` | `write_failed` + `rollback_failed` | The removal failed too. `remainingFiles` names exactly the paths that may still be there. |

### Preview, then apply

`--apply` does not consume the preview. It re-reads the manifest, the
four directories, their symlink state and both targets immediately
before it writes, so a file that appeared in between is a refusal rather
than an overwrite. `--apply` takes no value: `--apply=yes` is a rejected
invocation.

Each file is created with `fopen($path, 'x+b')`, which fails rather than
truncating anything already there, and every byte is written in a loop
that treats a failed write, or one that accepts nothing, as the end of
the attempt. If the second file cannot be created or finished, every
file this invocation created is removed — never one that was already
there. A removal that fails is reported, with the paths that may remain.

### What it cannot tell you

Whether the project already routes `GET /health` somewhere else.
Orbitron reads no application source and runs no discovery, so a
conflicting route is invisible to it and the apply succeeds. The
generated test is what surfaces it: the framework's own route discovery
refuses two controllers claiming one path, and the first run of the test
suite after the scaffold says so.

## Over MCP

```console
vendor/bin/kinetis-orbitron-mcp
```

A stdio MCP server speaking `2025-06-18`, for any client that launches a
server as a subprocess. Register the launcher as a stdio server named
`orbitron`, the way that client registers any other — it takes no
argument and needs no environment. [Wire it into a
project](#wire-it-into-a-project) is that registration checked in, with
the per-client configuration paths.

| Tool or resource | What it returns |
|---|---|
| `orbitron_inspect` | The `orbitron:inspect` document. Read-only. |
| `orbitron_verify` | The `orbitron:verify` document. Read-only; an error document comes back as an MCP error result still carrying the document. |
| `orbitron_scaffold_plan` | The `orbitron:scaffold` preview document. Read-only. |
| `orbitron_scaffold_apply` | The `orbitron:scaffold --apply` document, and creates the two files. |
| `orbitron_read_package_source` | One line window of one installed package's own source. Read-only. |
| `orbitron_search_package_source` | The lines of one such file that contain a literal string. Read-only. |
| `orbitron_list_package_source` | The direct children of one directory of such a package, with the kind of each. Read-only. |
| `kinetis_read_doc` | One line window of one Kinetis documentation page. Read-only, and the one tool that reaches the network. |
| `kinetis://orbitron/context` | The `orbitron:context` document, as Markdown. |
| `kinetis://docs/<page>` | One Kinetis documentation page, as Markdown. `resources/list` names every page; start at `kinetis://docs/agent-workflow`. |

`orbitron_scaffold_apply` writes to the project. Selecting it *is* the
mutation request: it takes no argument, and your MCP client's own
approval policy is what decides whether it runs. It is annotated
`destructiveHint: true` and `idempotentHint: false`, because a second
apply refuses rather than overwriting.

Four tools publish a closed, empty input schema and refuse a call
carrying any argument. The other four have closed schemas of their own:
`orbitron_read_package_source`, `orbitron_search_package_source` and
`orbitron_list_package_source` are described below, and
`kinetis_read_doc` takes a page URI from the fixed catalogue plus an
optional line window — `kinetis/mcp-docs` publishes and validates it,
and this server surfaces it unchanged. No message can otherwise name a
project root, a source body, a URL, an origin, a ref or a command, and
the server never boots the Kinetis application.

The server reads this project's generated
`vendor/composer/installed.php` again for every operation that reports or
uses installed package facts, so a completed `composer require`, `remove`
or `update` is visible to the next such call — there is nothing to restart
or reconnect. One operation reads it once, so the version a document
reports and the source a read opens always come from the same inventory,
and the snapshot is discarded with the response. An inventory that is
absent or not the generated shape fails an operation that needs it rather
than producing an answer from an older one. Every other document, and the
file content and directory entries the three installed-source tools
report, are re-read on each call too.

### The installed-source tools

`orbitron_read_package_source` reads one bounded line window of one
installed package's own source — the exact source this project has
installed, when a documentation page published from `main` could
describe a newer release, or when an exact dependency's behavior is what
a decision turns on. Any real installed, non-root package qualifies,
whatever its vendor: `orbitron_inspect` names the `kinetis/*` ones, and
the project's own `composer.lock` names every other. The project's own
checkout is not one of them.

| Argument | Type | Constraint |
|---|---|---|
| `package` | string | Required, non-empty, an installed, non-root package name. |
| `path` | string | Required, non-empty, at most 256 Unicode code points. |
| `startLine` | integer | Optional, at least 1, default `1`. |
| `lineCount` | integer | Optional, 1 to 200, default `200`. |

`path` must resolve to exact `composer.json`, exact `README.md`, or a
file beneath `src/`, `bin/` or `resources/`. The resolved target — a
symlink included, re-admitted against that same location once resolved —
must stay inside it, must be a regular file, must be at most 1 MiB, and
must be UTF-8 text with no NUL byte. Every argument is validated against
this schema before any lookup or read runs; a call outside it is a
JSON-RPC `-32602` protocol error, not a refusal document.

A success reports `status: "ok"`, `package`, `version`, `path`,
`startLine`, `endLine`, `hasMore` and `content`. `hasMore: true` is a
success, not a refusal: continue by calling again with `startLine` set
to `endLine + 1` until the needed evidence is in view or `hasMore` is
`false`. A refusal reports only `status: "error"` and one `code`:
`package_unknown`, `path_not_admitted`, `source_missing`,
`source_unreadable`, `source_oversize`, `source_not_text`, or
`line_out_of_range`.

`orbitron_search_package_source` searches one such file instead of
paging through it, and takes the same `package` and `path`:

| Argument | Type | Constraint |
|---|---|---|
| `query` | string | Required, non-empty, at most 256 Unicode code points. |
| `startLine` | integer | Optional, at least 1, default `1`. |

The scan is literal and case-sensitive — no pattern, no case mode, no
result count, and one file per call rather than a directory or a
package. A success reports `status: "ok"`, `package`, `version`, `path`,
`query`, `startLine`, `matches` and `hasMore`. `matches` lists at most
50 `{"line": <integer>, "content": <string>}` entries in source order,
each line without the newline the file stores after it; finding nothing
is a success with an empty list. `hasMore: true` means a later line
matches too — call again with `startLine` set to the last reported line
plus one. The refusal codes are the ones above.

`orbitron_list_package_source` is how a file is found when the package
is known and the path is not, and takes only the same `package` and a
`path` naming a directory:

| Argument | Type | Constraint |
|---|---|---|
| `package` | string | Required, non-empty, an installed, non-root package name. |
| `path` | string | Required, non-empty, at most 256 Unicode code points. |

`path` must name `src/`, `bin/` or `resources/` itself, or a directory
beneath one of them; the package root and its two readable root files
are not listable. There is no recursion, pattern, filter or paging
argument — a subdirectory is listed by naming it in the next call.

A success reports `status: "ok"`, `package`, `version`, `path` and
`entries`: the direct children, each as a `name` and a `type` of
`"file"` or `"directory"`, in bytewise name order. An empty directory is
a success with an empty list. A child is reported only when its resolved
target is a regular file or a directory still inside the same admitted
location, so a link out of the package, a link to a part of it this tool
does not serve, a dangling link and a special file are absent rather
than offered as something to read. A refusal reports only
`status: "error"` and one `code`: `package_unknown`, `path_not_admitted`,
`source_missing`, `source_unreadable`, `source_not_directory` for an
admitted path behind a regular file, or `directory_oversize` for a
directory of more than 200 reportable children — which refuses the whole
listing rather than reporting part of one.

Use them together: list the package's `src/` and the directory the task
is about, or derive a file from the class and that package's own
`composer.json` autoload map, or find it by searching the package's
`README.md` for the option or term; then search the file and read a
window around a reported line. No tool here searches across a package,
so read `vendor/<vendor>/<package>` directly when none of those routes
finds the file.

### The documentation resources

[`kinetis/mcp-docs`](https://kinetis.dev/docs/mcp-docs.html) owns the
catalogue, the fetch and the window tool. Orbitron installs it as a
dependency and publishes its resources and that tool from this one
connection, composing that server rather than copying it, so registering
Orbitron is the whole registration: there is nothing else to add for the
documentation. The
package stays framework-agnostic and independently installable, so a
project that wants the documentation without the harness registers
`vendor/bin/kinetis-mcp-docs` on its own instead of Orbitron.

Read a page whole as its `kinetis://docs/<page>` resource, or call
`kinetis_read_doc` with that URI for one bounded window of it: at most
200 lines and 32 KiB of content per call, reporting the `endLine` it
reached and whether more follows, so a page too long for one tool result
is read in order without a cursor. Concatenating a page's windows
reproduces it exactly, as long as the page has not changed on `main`
between calls — every call fetches it again, and nothing is cached or
snapshotted. That tool's schema, bounds, refusal codes and
documentation belong to `kinetis/mcp-docs`.

A page is fetched when it is read, from a URL built out of that package's
two constants — nothing chooses an origin, a ref or a path. TLS is
verified, no redirect is followed, a 10-second idle timeout and a
30-second deadline bound the request, the body is abandoned as soon as it
passes a 4 MiB cap, and one that is not valid UTF-8 is refused rather
than encoded. A fetch that fails is a generic MCP error naming only the
URI that was asked for; the URL, the status and the transport's message
go to the server's stderr, where your client's own log is what shows
them. Stdout carries JSON-RPC frames and nothing else.

Those pages are published from `main`, so they can describe behavior
newer than this project has installed. `orbitron_inspect` and the
installed source stay the authority for anything version-sensitive.

## Wire it into a project

`kinetis/skeleton` arrives with all of this in place. For an application
that already exists, the checked-in form of the registration above is:

- `composer require --dev kinetis/orbitron` — plus `kinetis/mcp-docs`
  and `kinetis/mcp-protocol` in a monorepo that resolves siblings
  through `path` repositories, because a root whose `minimum-stability`
  is `stable` will not take those siblings' `dev-main` from Packagist;
- `bin/orbitron-mcp`, executable. For a project that runs in Docker it
  is a one-file bridge that resolves its own project directory and
  `run`s a disposable container from the `app` service's own image —
  `docker compose --project-directory "$dir" run --rm -T --no-deps
  --entrypoint php app vendor/bin/kinetis-orbitron-mcp` — sharing
  `app`'s mounts but not its process lifecycle, so restarting,
  recreating or rebuilding `app` does not disconnect an established
  session. `--entrypoint php` skips the application entrypoint's
  `composer install`; `--no-deps` starts only this one container; `-T`
  is required, because an allocated TTY would rewrite the protocol's
  newline-delimited frames. The stack's initial `docker compose up
  --build -d` must have completed at least once, so the image is
  available and its vendor volume is populated with dependencies. With
  PHP on the host there is no bridge — the launcher is
  `./vendor/bin/kinetis-orbitron-mcp` itself;
- `AGENTS.md`, the one place the agent contract is written: on the first
  application task of a session, confirm the `orbitron` tools, read
  `kinetis://orbitron/context` and `kinetis://docs/agent-workflow`, call
  `orbitron_inspect`, call `orbitron_verify`, and then either one
  readiness line beginning `Orbitron ready` or no application change at
  all and the exact failure;
- `CLAUDE.md` and `GEMINI.md` containing `@AGENTS.md` and nothing else;
- `.mcp.json`, `.codex/config.toml` and `.gemini/settings.json`, each
  naming one stdio server `orbitron` launched as `./bin/orbitron-mcp`,
  with no `env` block, no `trust` key and no preapproved tool.

These files register a server; they change no policy. Carrying no
credential, no trust override and no preapproval, they leave the
client's own trust and approval policy authoritative — and that policy
differs. Claude Code prompts about a project-scoped `.mcp.json` in an
interactive session, while its documented non-interactive and
policy-managed modes can behave differently; Codex reads a project's
`.codex/config.toml` only for a trusted project; Gemini CLI may ignore
workspace settings in an untrusted workspace, and an omitted server
`trust` leaves that server's default `false`. Reload or restart the
client when the configuration was added or changed after the current
session started, or when its tool catalog has not picked the server up
yet — a Markdown file read inside a running session cannot register a
server into it.

The [Orbitron documentation](https://kinetis.dev/docs/orbitron.html)
carries the full recipe — the bridge verbatim, each configuration file,
the readiness and blocked handshake, the shell fallback, and the
diagnostics for a stopped container and a disconnected server.

## Output and exit codes

Every command writes exactly one document plus a trailing newline to
STDOUT, with fixed key and list order. None writes progress text.

| Exit | Meaning |
|---|---|
| `0` | The document was written; the verification reports no error, and the scaffold preview is ready or its apply completed. |
| `2` | An unsupported `--format`, a bare `--format` naming nothing, an `--apply` carrying a value, or a positional argument. STDERR names the accepted invocation; STDOUT stays empty. |
| `3` | `orbitron:verify` and `orbitron:scaffold` only: the operation completed and the document it wrote reports an error, a refusal, or a failed write. |
| `1` | A launcher or uncaught failure, from `vendor/bin/kinetis` itself. |
| `70` | The command finished, and disposing the request scope afterwards failed — also the framework binary's own behavior. |

`CommandArguments` cannot enumerate options a command never reads, so an
unknown option is ignored rather than rejected. Orbitron adds no parser
of its own for it.

The MCP server reports the same outcomes differently: a refusal or a
failed write is an MCP result with `isError: true` still carrying the
document, and the binary exits `0` at end of input and `1` when a write
to stdout fails.

## Trust boundary

Orbitron reads three things on this machine and nothing more: Composer's
installed-package metadata, the project's own `composer.json` — bounded
as described above — and, for the scaffold, the existence and symlink
state of four fixed directories and two fixed paths. Over MCP only, it
also reads a bounded line window of one real installed, non-root
package's own `composer.json`, `README.md`, or a file beneath `src/`,
`bin/` or `resources/`, searches one such file for a literal string, and
lists the direct children of `src/`, `bin/` or `resources/` or of a
directory beneath one — never the Composer root project, the
application's own source, or anything in an installed package outside
those five locations, its own tests included. No configuration, no
credentials. It starts no process. That metadata is the one generated
file `vendor/composer/installed.php`: a command reads it through
`Composer\InstalledVersions`, which loads it itself, and the MCP server
evaluates that same fixed path under the detected project root, which no
message can name. Beyond that one file, the commands and every
MCP tool but three reach nothing else under `vendor/`; only
`orbitron_read_package_source`, `orbitron_search_package_source` and
`orbitron_list_package_source` read further, and only inside the one
admitted location of the one installed, non-root package a call
names — see [The installed-source
tools](#the-installed-source-tools). Every command declares
`bootstrap: false`, and the MCP binary boots no application at all, so
no package or application bootstrap runs either way.

One operation leaves this machine, and only over MCP: reading a
documentation page — whole as a `kinetis://docs/*` resource, or one
window of it with `kinetis_read_doc` — which `kinetis/mcp-docs` fetches
over HTTPS from its own fixed origin under the bounds
[above](#the-documentation-resources). It carries no credential, sends
nothing about your project, and no message can redirect it. The four
commands and the seven Orbitron tools reach no network at all.

It writes two files, both fixed, both only on `orbitron:scaffold --apply`
or `orbitron_scaffold_apply`, and both through a create that refuses an
occupied path. Neither a command line nor an MCP message supplies a path
to either write target: the project root comes from the framework's own
`Kinetis\Runtime\ProjectRoot::detect()`, reading Composer's generated bin
proxy, and every name below it is a constant. The three installed-source
tools are the only calls that do take a caller-supplied path, and only
for reading — admitted against a fixed set of locations, with the
resolved target re-admitted, before anything reaches the filesystem. The MCP
server is a local process your client launches, so that process and
your filesystem permissions are the authority boundary.

A command invocation as a whole is still not side-effect-free, and what
remains belongs to the framework launcher rather than to Orbitron (the
MCP binary loads no `.env` and compiles no cache):
`vendor/bin/kinetis` loads `.env` before it dispatches any command, and
under `APP_ENV=production` it compiles `.kinetis-cache/compiled.php`
when no valid artifact is present. `bootstrap: false` prevents neither.

## Removal

Orbitron is a `require-dev` package. Generated and application
production code never depends on it, so
`composer remove --dev kinetis/orbitron` changes nothing an application
does — only the commands and the MCP binary go away.

See the [Orbitron documentation](https://kinetis.dev/docs/orbitron.html)
for the full workflow, and
[Agent Workflow](https://kinetis.dev/docs/agent-workflow.html) for where
a task goes next.

## License

MIT — see [LICENSE](LICENSE).
