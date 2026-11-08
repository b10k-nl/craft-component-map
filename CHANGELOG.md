# Changelog

All notable changes to Component Map are documented here. This project adheres
to [Semantic Versioning](https://semver.org).

## 1.2.0 - 2026-11-08

### Added

- Craft's MCP server (Craft 5.12+): read-only tools `component-map.overview`,
  `component-map.show`, `component-map.impact` and `component-map.unused`,
  two checks in `content-model.audit` (`component-map-broken-references`,
  `component-map-blocks-without-template`), and this project's dispatchers in
  `info.get`. Only where `allowAdminChanges` is on; the new `mcp` setting
  turns it off. Nothing changes on Craft 5.11 and earlier.
- `ignore missing` / `ignore_missing = true`, and a fallback list with an entry
  that exists, mark a missing template as intended.

### Changed

- The console commands and the MCP tools share one report, so `--json` and an
  agent see the same shape.
- CI also runs weekly and on demand, against the newest Craft and MCP SDK.

## 1.1.0 - 2026-10-05

### Added

- `component-map/unused` — blocks no entry contains, templates nothing
  reaches (including whole dead chains), entry types in no section or Matrix
  field, and Matrix fields on no entry type. Warns when a reached template
  includes something chosen at runtime.
- `entryPoints` setting — templates rendered from PHP, `config/routes.php` or
  a plugin setting, so `unused` does not report them.

### Changed

- A shorter package description.

## 1.0.0 - 2026-09-29

First release.

### Added

- Template references read with Twig's own lexer: `include`, `embed`,
  `extends`, `import`, `from`, `use`, `include()`, `source()` — including
  arrays of fallbacks, conditionals, `??`, and dynamic patterns such as
  `'_adapters/' ~ block.type.handle ~ '.twig'`, resolved against the files
  that exist. Files the lexer rejects still yield their static references.
- Which entries contain which blocks, at any depth, from Craft's ownership
  table (canonical content only; `readEntries` turns it off). `impact` lists
  the entries to check with their URLs; `show` lists where a block is used.
- The content model's schema: sections and their templates, entry types,
  Matrix fields and the block types they allow, category groups.
- Dispatcher detection: a dynamic include resolving to a file named after an
  entry type links that entry type to its template.
- `component-map/build` — the whole map as Markdown (`--stdout`) or JSON
  (`--json`); otherwise a local snapshot in `storage/component-map/`. Nothing
  is written into the repository: the plugin runs where you develop and
  `show` / `impact` build the map from the current templates on every call.
- `component-map/show` — where a template, entry type, section or field is
  used, what it uses, which blocks and pages a change affects.
- `component-map/impact` — what changing files affects; `--git` for
  uncommitted changes, `--since=<branch>` for the whole branch (commits since
  it left that branch, plus uncommitted changes). Deleted templates count
  through the templates that still reference them. Project config changes
  (entry types, Matrix fields, sections, category groups) count through the
  content model: a nested block affects the blocks it sits in and their pages.
  Templates excluded by `ignore` are listed separately. Suggests
  `component-check/test` for the affected blocks when Component Check is
  installed.
- `component-map/agents` — the note that points a coding agent to the
  commands; `--file=AGENTS.md` / `CLAUDE.md` adds or updates it between
  markers. Uses `ddev craft` in DDEV projects.
- `--json` on every command; stable exit codes.
