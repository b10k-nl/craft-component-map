# Changelog

All notable changes to Component Map are documented here. This project adheres
to [Semantic Versioning](https://semver.org).

## Unreleased

First working draft.

### Added

- Template references read with Twig's own lexer: `include`, `embed`,
  `extends`, `import`, `from`, `use`, `include()`, `source()` — including
  arrays of fallbacks, conditionals, `??`, and dynamic patterns such as
  `'_adapters/' ~ block.type.handle ~ '.twig'`, resolved against the files
  that exist. Files the lexer rejects still yield their static references.
- The content model's schema: sections and their templates, entry types,
  Matrix fields and the block types they allow, category groups.
- Dispatcher detection: a dynamic include resolving to a file named after an
  entry type links that entry type to its template.
- `component-map/build` — writes `COMPONENT-MAP.md` for people and agents, and
  `graph.json`.
- `component-map/show` — where a template, entry type, section or field is
  used, what it uses, which blocks and pages a change affects.
- `component-map/impact` — what changing files affects; `--git` for
  uncommitted changes. Reports entry type handles ready for
  `component-check/test`.
- `--json` on every command; stable exit codes.
