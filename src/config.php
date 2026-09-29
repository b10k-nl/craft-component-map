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

    // Glob patterns (relative to templates/) left out of the map.
    'ignore' => ['*.stories.twig'],
];
