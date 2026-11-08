<?php

/**
 * Component Map config.
 *
 * Copy this file to `config/component-map.php` in your project and adjust.
 * Every setting is optional; these are the defaults.
 */

return [
    // Where `component-map/build` writes the Markdown map. Local by default:
    // `show` and `impact` read the templates directly, so there is nothing to
    // commit. '' = skip.
    'markdownFile' => '@storage/component-map/COMPONENT-MAP.md',

    // Where `component-map/build` writes the full graph as JSON. '' = skip.
    'jsonFile' => '@storage/component-map/graph.json',

    // List the entries (with URLs) that contain the affected blocks, in
    // `impact` and `show`. Read-only. false = templates and schema only.
    'readEntries' => true,

    // Tools, audit checks and instructions on Craft's MCP server (Craft 5.12+),
    // only where allowAdminChanges is on. false = none at all.
    'mcp' => true,

    // Templates rendered from outside Twig (PHP, config/routes.php, a plugin
    // setting), so `unused` does not report them. Globs relative to templates/.
    'entryPoints' => [],

    // Glob patterns (relative to templates/) left out of the map.
    'ignore' => ['*.stories.twig'],
];
