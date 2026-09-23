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
use with this project's own Kinetis context, package inspection, layout
verification, controlled scaffolding and the current Kinetis
documentation, so it builds against the packages and versions actually
installed.

Orbitron contains no model of its own and talks to none: you bring the
agent. What it gives that agent is four documents — a portable Kinetis
context document, this project's installed `kinetis/*` package inventory
as JSON, a deterministic verification of the Composer layout, and one
health-endpoint scaffold you preview before you apply it — plus, over
MCP, the Kinetis documentation and three tools that reach an installed
package's own source. None of it is evidence that application code is
correct; the project's own test suite and review are.

It is a `require-dev` package. No production code depends on it, and
`composer remove --dev kinetis/orbitron` changes nothing an application
does.

## Start a new project

```console
docker run --rm -v "$PWD":/app -w /app composer:2 \
    create-project --no-install kinetis/skeleton my-app
cd my-app
cp .env.example .env
docker compose up --build -d
```

`kinetis/skeleton` ships with Orbitron already wired in. Open or
reconnect your MCP-capable coding agent in that directory, approve the
project-local `orbitron` server the first time your client asks, and the
agent initializes on the earliest turn its `orbitron` tools and
resources are listed — checking again each turn until they are —
reporting readiness before it takes your first request:

```
Orbitron ready — kinetis/framework <installed-version>, layout pass (App\, App\Tests\).
```

## Add it to an existing project

```console
composer require --dev kinetis/orbitron
```

Then check in a launcher, the agent contract, and one MCP configuration
file per client — the same wiring the skeleton ships. See [Equip an
existing
project](https://kinetis.dev/docs/appendix-orbitron.html#equip-an-existing-project)
for every file verbatim.

## The four commands

```console
vendor/bin/kinetis orbitron:context   # what Orbitron is, links to the guides, installed kinetis/* versions
vendor/bin/kinetis orbitron:inspect   # the project and checkout roots and installed kinetis/* inventory, as JSON
vendor/bin/kinetis orbitron:verify    # whether this project's Composer layout is the one Orbitron supports
vendor/bin/kinetis orbitron:scaffold  # a GET /health controller and test — preview, then --apply
```

Each writes one JSON or Markdown document to STDOUT. `orbitron:scaffold`
is the only one that writes to the project, and only with `--apply`.

## Over MCP

```console
vendor/bin/kinetis-orbitron-mcp
```

A stdio MCP server speaking `2025-06-18`. Register it as a stdio server
named `orbitron`; it takes no argument. A launcher that runs it in a
container sets `KINETIS_ORBITRON_CHECKOUT_ROOT` to the checkout's
absolute host path, which `orbitron_inspect` reports as `checkoutRoot`;
see [`orbitron:inspect`](https://kinetis.dev/docs/appendix-orbitron.html#orbitron-inspect).

| Tool or resource | What it does |
|---|---|
| `orbitron_inspect`, `orbitron_verify` | The two read-only documents above, as tool calls. |
| `orbitron_scaffold_plan`, `orbitron_scaffold_apply` | The scaffold preview, and the one tool that writes. |
| `orbitron_read_package_source`, `orbitron_search_package_source`, `orbitron_list_package_source` | A bounded window, a literal search, and a directory listing of one real installed, non-root package's own source — any package this project installed, not only `kinetis/*`. |
| `kinetis_read_doc`, `kinetis_search_doc`, `kinetis://docs/<page>` | The Kinetis documentation, as bounded windows, a literal search of one page, and whole pages — composed from [`kinetis/mcp-docs`](https://kinetis.dev/docs/mcp-docs.html), the one operation that reaches the network. |
| `kinetis://orbitron/context` | The `orbitron:context` document. |

## Documentation

- [The Orbitron guide](https://kinetis.dev/docs/orbitron.html) — what
  Orbitron is, the skeleton's short path, and troubleshooting.
- [Appendix: Orbitron](https://kinetis.dev/docs/appendix-orbitron.html) —
  every command's schema and exit codes, the installed-source tools, the
  full MCP catalogue, project wiring, diagnostics and the trust
  boundary.
- [Agent Workflow](https://kinetis.dev/docs/agent-workflow.html) — where
  a task goes once Orbitron is ready.

## License

MIT — see [LICENSE](LICENSE).
