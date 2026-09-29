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

It answers “where is this used?” and “what does this change affect?” from
the command line, as text or JSON — built from your templates as they are
right now, on every call. It runs where you develop: nothing is generated
into the repository, so there is no map to commit, keep up to date or merge.

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
- **The content model**: sections and the template each renders with, entry
  types, Matrix fields and the block types they allow, category groups.
- **The link between them that generic tools cannot see.** A dynamic include
  that resolves to a file named after an entry type — `_adapters/hero.twig` for
  entry type `hero` — is recorded as “a Hero block is rendered by this
  template, via this dispatcher”. So the map knows which blocks a component
  change affects, not only which files include it.

- **Which entries use which blocks** — read from Craft's ownership table, at
  any depth of nesting. So `impact` ends with the actual pages to open in the
  browser, and `show hero` tells you where the Hero block is used — or that
  no entry uses it yet.

What it cannot resolve (an include of a bare variable, a missing template) is
listed under **Not resolved**, not silently dropped.

## Requirements

Craft CMS 5, PHP 8.2+. Nothing else — no Node.

## Installation

```bash
composer require b10k/craft-component-map
php craft plugin/install component-map
php craft component-map/agents --file=AGENTS.md    # optional: tell your coding agent
```

Read-only and console-only: it reads templates, the content model and which
entries contain which blocks (canonical content only — no drafts or
revisions; turn it off with `readEntries`), writes only to `storage/` (and to `AGENTS.md` when you ask it to),
and exposes nothing over HTTP. It is meant for local development — where you
and your coding agent edit templates — not for staging or production.

## Commands

| Command | What it does |
|---|---|
| `component-map/show <query>` | Where a template, entry type, section or Matrix field is used, what it uses, and for a block the entries it is on. `<query>` can be `_blocks/hero`, `templates/_blocks/hero.twig`, `hero` (entry type) or `home` (section). `--json`. |
| `component-map/impact <files>` | What changing these files affects: templates above them, blocks rendered through them, pages that reach them. `--git` uses your uncommitted changes; `--since=main` everything that changed on your branch, committed or not. `--json`. |
| `component-map/build` | The whole map: `--stdout` prints it as Markdown, `--json` as a graph. Without either, writes both to `storage/component-map/` (a local snapshot, not for committing). |
| `component-map/agents` | Prints the note that points a coding agent to these commands; `--file=AGENTS.md` (or `CLAUDE.md`) adds it to that file, or updates it. |

`show` and `impact` read the templates on every call — there is nothing to
rebuild first.

Exit codes: `0` ok · `1` not found · `2` could not run.

### Impact

```
$ php craft component-map/impact --git

Blocks (entry types) affected
  cardsGrid

Pages affected
  home   (entries with these blocks)
  news   (entries with these blocks)

Entries to check (3)
  Home              https://example.test/                cardsGrid
  Our studios       https://example.test/studios         cardsGrid
  Spring timetable  https://example.test/news/spring     cardsGrid

Templates affected
  _adapters/cardsGrid.twig
  _blocks.twig
  _components/card.twig
  _components/cards-grid.twig
  index.twig
  news/_entry.twig

Content model changed (project config)
  entry type card

Other files — not analysed (CSS, JS and PHP can affect any page)
  web/dist/app.css
```

**Entries to check** are the pages to open: every entry that contains an
affected block (however deeply nested), and every entry of a section whose
page template, layout or a component they include changed. Entries that are
not live say so (`disabled`, `pending`, `expired`). `--limit=0` lists all.

A block is affected when a changed file is on its render path: its adapter,
anything the adapter includes, and the dispatcher, page templates and layouts
above it. So changing a card component affects Cards Grid, not Hero — and
changing the layout affects every block.

Changes to the content model count too. A project config file such as
`config/project/entryTypes/card--….yaml` is read as “entry type Card changed”:
it affects the Card block, the Cards Grid it sits in, and the pages that show
them. The same goes for Matrix fields, sections and category groups — the link
between content and templates is what the map is for. Templates left out by
the `ignore` setting (Component Guide stories) are listed separately.

Before you open a pull request, check the whole branch — what you committed
and what you have not yet:

```bash
php craft component-map/impact --since=main
```

It compares with the point where your branch left `main`, so work merged into
`main` since then is not counted. A deleted template is reported as deleted,
and counts through the templates that still reference it: they now break.
The list of blocks and pages is what to check in the browser.

## With Component Check

Component Map is useful on its own. If you also use
[Component Check](https://github.com/b10k-nl/craft-component-check), which
tests blocks in a browser by entry type handle, `impact` prints the command
to test exactly the affected blocks, and you can chain them:

```bash
php craft component-check/test $(php craft component-map/impact --since=main --json | jq -r '.entryTypes | join(",")')
```

## For coding agents

The map is not a file in your repository, so an agent will not find it by
searching. Tell it once:

```bash
php craft component-map/agents --file=AGENTS.md    # or CLAUDE.md
```

That adds a short note — between `<!-- component-map -->` markers, so running
it again updates it instead of adding a second copy — telling the agent to run
`show` before editing a template and `impact --git` after. It uses `ddev craft`
when the project runs in DDEV. The note does not change when your templates
do, so it is safe to commit. The full instructions are in
[AGENTS.md](AGENTS.md) in this package.

## Configuration

`config/component-map.php`:

| Setting | Default | |
|---|---|---|
| `markdownFile` | `@storage/component-map/COMPONENT-MAP.md` | Where `build` writes Markdown. Empty = do not write it |
| `jsonFile` | `@storage/component-map/graph.json` | Where `build` writes JSON. Empty = do not write it |
| `readEntries` | `true` | List the entries that contain affected blocks. `false` = templates and schema only |
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
