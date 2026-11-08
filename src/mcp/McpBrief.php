<?php

namespace b10k\componentmap\mcp;

/**
 * Component Map's words for Craft's MCP server: the instructions in `info.get`
 * (what this project's templates look like, which tool to use when) and the
 * one line every client gets on connecting.
 *
 * Craft-free: built from the overview, unit-tested with plain arrays.
 */
final class McpBrief
{
    public const CHECK_BROKEN_REFERENCES = 'component-map-broken-references';
    public const CHECK_BLOCKS_WITHOUT_TEMPLATE = 'component-map-blocks-without-template';

    /** Short and harmless: every client gets it, with or without control-panel access. */
    public const SERVER_LINE = 'Component Map: the `component-map.*` tools map this site\'s Twig templates to its pages and page-builder blocks, and tell what a template change affects. Details in `info.get`.';

    /**
     * @param array<string, mixed> $overview Explorer::overview()
     * @param bool $tools Whether the tools made it onto the server.
     * @param bool $entries Whether entries are read (the readEntries setting).
     */
    public static function text(array $overview, bool $tools, bool $entries): string
    {
        $c = $overview['counts'];
        $lines = [];
        $lines[] = '## Component Map';
        $lines[] = sprintf(
            'How this site\'s Twig templates fit together and with the content model — which template renders each page and each page-builder block, and what a template change affects. Craft\'s own tools do not see templates; these do. This site: %s, %s, %s.',
            self::count($c['templates'], 'template'),
            self::count($c['sections'], 'section'),
            self::count($c['matrixFields'], 'Matrix field'),
        );

        foreach (array_slice($overview['dispatchers'], 0, 3) as $d) {
            $lines[] = sprintf(
                'Page-builder blocks are rendered by `%s`%s: %s.',
                $d['template'],
                $d['patterns'] !== [] ? ' through `' . implode('`, `', $d['patterns']) . '` (a template named after the block\'s entry type handle)' : '',
                self::list($d['renders'], 8),
            );
        }
        if ($overview['dispatchers'] === []) {
            $lines[] = 'No dispatcher was found (a dynamic include that picks a template by the block\'s entry type), so blocks are not linked to templates of their own.';
        }

        if ($tools) {
            $lines[] = 'Before changing a template, block or section: `component-map.show`. After changing files: `component-map.impact` with the files'
                . ($entries ? ' — it returns the entries to open in a browser, with URLs' : '')
                . '. Before deleting: `component-map.unused` (candidates, not a delete list). A first look: `component-map.overview`.';
        }

        $blind = (int)($overview['notResolved']['total'] ?? 0);
        $lines[] = 'Not in the map: templates rendered from PHP, plugins, Sprig or JavaScript'
            . ($blind > 0 ? sprintf(', and %s it cannot follow (a template chosen at runtime, or one that is missing)', self::count($blind, 'reference')) : '')
            . '. Check those by hand.';

        return implode("\n", $lines);
    }

    private static function count(int $n, string $what): string
    {
        return $n . ' ' . $what . ($n === 1 ? '' : 's');
    }

    /**
     * @param string[] $items
     */
    private static function list(array $items, int $max): string
    {
        $shown = array_slice($items, 0, $max);
        $more = count($items) - count($shown);
        return implode(', ', $shown) . ($more > 0 ? " and {$more} more" : '');
    }
}
