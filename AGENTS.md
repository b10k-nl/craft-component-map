# Component Map — instructions for coding agents

This file is for a coding agent working in a Craft CMS project that has the
Component Map plugin installed. Use it before you change, move, rename or
delete a Twig template.

## Before you edit

Use `ddev craft` instead of `php craft` if the project runs in DDEV. Every
command reads the templates as they are now; there is no map file to find or
rebuild first.

1. **For an overview**, print the whole map:

   ```bash
   php craft component-map/build --stdout
   ```

   It lists which template renders each page and each page-builder block, and
   for every template what uses it and what it uses.

2. **Ask about the template you are about to touch:**

   ```bash
   php craft component-map/show _components/button --json
   ```

   - `usedBy` — every template that includes, embeds or extends it. Changing
     its variables or blocks affects all of them.
   - `affects.entryTypes` — the page-builder blocks (Matrix entry types) whose
     rendering goes through it.
   - `affects.pages` — the sections whose pages reach it.

   `show` also takes an entry type handle (`hero`), a section (`home`) or a
   Matrix field (`contentBlocks`).

## After you edit

3. **Check what you changed affects:**

   ```bash
   php craft component-map/impact --git --json
   ```

   Before the work is handed over as a branch or pull request, check the whole
   branch instead: `php craft component-map/impact --since=main --json`
   (committed and uncommitted changes since the branch left `main`).

   Report `entryTypes` and `pages` to the human — those are the blocks and
   pages to check. `deleted` lists templates that are gone; the templates that
   still reference them are in `templates` and will fail to render. If
   Component Check is installed, test exactly those blocks:
   `php craft component-check/test <entryTypes joined by commas> --json`.

4. **`contentModel`** lists entry types, Matrix fields and sections changed in
   project config; their blocks and pages are already in `entryTypes` and
   `pages`. **`ignored`** are templates the project leaves out of the map
   (e.g. Component Guide stories).

5. **`unmapped` files** (CSS, JS, PHP) are outside the template map. They can
   affect anything; say so rather than assuming they do not.

## Rules

- A reference marked `dynamic` is resolved from a pattern
  (`'_adapters/' ~ block.type.handle`). Renaming a file matched by it changes
  which block type it renders — treat that as a content-model change.
- Entries under “Not resolved” are references the map could not follow. Do not
  assume nothing uses a template because the map shows no `usedBy`; search for
  its name too.
- Do not commit the map (`build` output or anything in
  `storage/component-map/`); it is generated locally.
