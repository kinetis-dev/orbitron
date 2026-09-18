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

Over MCP it also serves the Kinetis documentation, so the agent reads the
guidance from the same connection instead of a second server you would
have to configure and it could skip.

```console
composer require --dev kinetis/orbitron
```

Reach them either way: four commands on `vendor/bin/kinetis`, each
writing one document to STDOUT, or the stdio MCP server
`vendor/bin/kinetis-orbitron-mcp`, which serves the same documents as
tools and the documentation as resources. Orbitron has no model of its
own and no shell. The commands reach no network; reading a documentation
resource over MCP is the one thing that does, and [it is bounded
below](#over-mcp).

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
| `kinetis://orbitron/context` | The `orbitron:context` document, as Markdown. |
| `kinetis://docs/<page>` | One Kinetis documentation page, as Markdown. `resources/list` names every page; start at `kinetis://docs/agent-workflow`. |

`orbitron_scaffold_apply` writes to the project. Selecting it *is* the
mutation request: it takes no argument, and your MCP client's own
approval policy is what decides whether it runs. It is annotated
`destructiveHint: true` and `idempotentHint: false`, because a second
apply refuses rather than overwriting.

All four tools publish a closed, empty input schema and refuse a call
carrying any argument. No message can name a project root, a path, a
source body, a URL, an origin, a ref or a command, and the server never
boots the Kinetis application. Composer's installed-package inventory is
process-cached, so restart the server after installing or removing a
dependency; every other document is re-read on each call.

### The documentation resources

[`kinetis/mcp-docs`](https://kinetis.dev/docs/mcp-docs.html) owns the
catalogue and the fetch. Orbitron installs it as a dependency and
publishes its resources from this one connection, composing that server
rather than copying it, so registering Orbitron is the whole
registration: there is nothing else to add for the documentation. The
package stays framework-agnostic and independently installable, so a
project that wants the documentation without the harness registers
`vendor/bin/kinetis-mcp-docs` on its own instead of Orbitron.

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
  `exec`s `docker compose --project-directory "$dir" exec -T app php
  vendor/bin/kinetis-orbitron-mcp`; `-T` is required, because an
  allocated TTY would rewrite the protocol's newline-delimited frames.
  With PHP on the host there is no bridge — the launcher is
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
diagnostics for a stopped container, a disconnected server and a stale
package inventory.

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
state of four fixed directories and two fixed paths. No other application
source, no configuration, no credentials. It starts no process. Reading
the installed metadata goes through `Composer\InstalledVersions`, which
loads `vendor/composer/installed.php` itself; Orbitron reaches nothing
beyond it. Every command declares `bootstrap: false`, and the MCP binary
boots no application at all, so no package or application bootstrap runs
either way.

One operation leaves this machine, and only over MCP: reading a
`kinetis://docs/*` resource, which `kinetis/mcp-docs` fetches over HTTPS
from its own fixed origin under the bounds
[above](#the-documentation-resources). It carries no credential, sends
nothing about your project, and no message can redirect it. The four
commands and all four tools reach no network at all.

It writes two files, both fixed, both only on `orbitron:scaffold --apply`
or `orbitron_scaffold_apply`, and both through a create that refuses an
occupied path. Neither a command line nor an MCP message supplies a path
to any of this: the project root comes from the framework's own
`Kinetis\Runtime\ProjectRoot::detect()`, reading Composer's generated bin
proxy, and every name below it is a constant. The MCP server is a local
process your client launches, so that process and your filesystem
permissions are the authority boundary.

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
