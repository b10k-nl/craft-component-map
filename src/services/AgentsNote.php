<?php

namespace b10k\componentmap\services;

/**
 * The few lines that tell a coding agent the map exists — for the project's
 * `AGENTS.md` / `CLAUDE.md`.
 *
 * The map itself is not committed: every developer runs the plugin locally and
 * it is built from the current templates on each call. An agent cannot find a
 * file that is not in the repository (and search tools skip ignored ones), so
 * this note — which never changes with the templates — is what points it to the
 * commands.
 *
 * Wrapped in markers, so adding it again replaces it instead of duplicating.
 *
 * Craft-free.
 */
final class AgentsNote
{
    public const START = '<!-- component-map -->';
    public const END = '<!-- /component-map -->';

    /**
     * @param string $craft How to run Craft in this project: `php craft`, `ddev craft`.
     */
    public static function text(string $craft = 'php craft'): string
    {
        return implode("\n", [
            self::START,
            '## Twig templates: Component Map',
            '',
            'This project has the Component Map plugin. Before you change, move, rename or delete a Twig',
            'template, ask what uses it and which page-builder blocks and pages it affects. After editing,',
            'check what your changes affect:',
            '',
            '```bash',
            "{$craft} component-map/show <template|entry type|section> --json",
            "{$craft} component-map/impact --git --json",
            '```',
            '',
            "The whole map as Markdown: `{$craft} component-map/build --stdout`. It is built from the current",
            'templates on every call — do not commit it. Details: `vendor/b10k/craft-component-map/AGENTS.md`.',
            self::END,
        ]);
    }

    /**
     * The file's content with the note added, or the earlier note replaced.
     */
    public static function addTo(string $content, string $craft = 'php craft'): string
    {
        $note = self::text($craft);
        $start = strpos($content, self::START);
        $end = $start === false ? false : strpos($content, self::END, $start);

        if ($start !== false && $end !== false) {
            return substr($content, 0, $start) . $note . substr($content, $end + strlen(self::END));
        }

        $content = rtrim($content);
        return ($content === '' ? '' : $content . "\n\n") . $note . "\n";
    }
}
