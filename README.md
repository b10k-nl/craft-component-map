# Component Map

**Which template renders the Hero block? What else breaks if I change
`_components/button.twig`?** In a Craft project the answer is spread over
Twig includes, a dispatcher that picks a template by `block.type.handle`, and
the content model in the control panel. Component Map puts it in one place —
for you, and for the coding agent that is about to edit your templates.

```
$ php craft component-map/show _blocks/hero

_blocks/hero.twig  (template)

Used by
  _adapters/hero.twig   include line 2

Uses
  —

A change affects blocks
  hero

A change affects pages
  home
```

It writes a `COMPONENT-MAP.md` a coding agent can read before touching
anything, and answers “where is this used?” and “what does this change
affect?” from the command line, as text or JSON.

> **Status:** `0.1.0-dev` — first working draft. Free (MIT).

---

## What it maps

- **Every template reference**, read with Twig's own lexer — not regular
  expressions — so comments, strings, `{% verbatim %}` and whitespace control
  behave as Twig sees them: `include`, `embed`, `extends`, `import`, `from`,
  `use`, `include()`, `source()`.
- **Dynamic and conditional references.** `{% include ['_adapters/' ~
  block.type.handle ~ '.twig', '_adapters/undefined.twig'] %}` becomes the
  pattern `_adapters/*.twig`, resolved against the files that exist, plus a
  fallback. `{% extends ajax ? '_bare' : '_site' %}` links to both.
- **The content model** (schema only, never entries): sections and the
  template each renders with, entry types, Matrix fields and the block types
  they allow, category groups.
- **The link between them that generic tools cannot see.** A dynamic include
  that resolves to a file named after an entry type — `_adapters/hero.twig` for
  entry type `hero` — is recorded as “a Hero block is rendered by this
  template, via this dispatcher”. So the map knows which blocks a component
  change affects, not only which files include it.

What it cannot resolve (an include of a bare variable, a missing template) is
listed under **Not resolved**, not silently dropped.

## Requirements

Craft CMS 5, PHP 8.2+. Nothing else — no Node.

## Installation

```bash
composer require b10k/craft-component-map
php craft plugin/install component-map
php craft component-map/build
```

Read-only and console-only: it reads templates and the content model's
schema, writes only the files it is asked to, and exposes nothing over HTTP.
Safe to install on every environment.

## Commands

| Command | What it does |
|---|---|
| `component-map/build` | Writes `COMPONENT-MAP.md` (commit it) and `storage/component-map/graph.json`. `--stdout` prints the Markdown instead; `--json` prints the whole graph. |
| `component-map/show <query>` | Where a template, entry type, section or Matrix field is used, and what it uses. `<query>` can be `_blocks/hero`, `templates/_blocks/hero.twig`, `hero` (entry type) or `home` (section). `--json`. |
| `component-map/impact <files>` | What changing these files affects: templates above them, blocks rendered through them, pages that reach them. `--git` uses your uncommitted changes. `--json`. |

Exit codes: `0` ok · `1` not found · `2` could not run.

### Impact

```
$ php craft component-map/impact --git

Blocks (entry types) affected
  cardsGrid

Pages affected
  home
  news

Templates affected
  _adapters/cardsGrid.twig
  _blocks.twig
  _components/card.twig
  _components/cards-grid.twig
  index.twig
  news/_entry.twig

Not templates (CSS, JS, PHP…) — may affect anything
  web/dist/app.css

Test them: php craft component-check/test cardsGrid
```

A block is affected when a changed file is on its render path: its adapter,
anything the adapter includes, and the dispatcher, page templates and layouts
above it. So changing a card component affects Cards Grid, not Hero — and
changing the layout affects every block.

## With Component Check

[Component Check](https://github.com/b10k-nl/craft-component-check) tests
blocks in a browser, by entry type handle — exactly what `impact` reports:

```bash
php craft component-check/test $(php craft component-map/impact --git --json | jq -r '.entryTypes | join(",")')
```

## For coding agents

Point your agent at `COMPONENT-MAP.md` (or add a line to `CLAUDE.md` /
`AGENTS.md`), and at [AGENTS.md](AGENTS.md) in this package for the commands.

## Configuration

`config/component-map.php`:

| Setting | Default | |
|---|---|---|
| `markdownFile` | `@root/COMPONENT-MAP.md` | Empty = do not write Markdown |
| `jsonFile` | `@storage/component-map/graph.json` | Empty = do not write JSON |
| `ignore` | `['*.stories.twig']` | Glob patterns (relative to `templates/`) to leave out |

## Limitations (v0.1)

- **Static only.** A reference whose target is decided at runtime (a variable
  from a field, a plugin's template) is listed as unresolved. Recording which
  templates a real page render actually uses is next.
- **Dispatchers are recognised by file name**: `_adapters/hero.twig` for entry
  type `hero` (`cards-grid`, `cards_grid` and `cardsGrid` all match
  `cardsGrid`). A dispatcher that maps handles to templates in a hash is not
  recognised yet.
- **Site templates only**: plugin templates (`@formie/…`) and `_self` macros
  are not part of the map.

## Development

```bash
composer install && composer check     # PHPUnit + PHPStan
```

The scanner, resolver, graph, impact analysis and Markdown writer are
Craft-free and unit-tested against a fixture site.

## License

MIT
