<?php

namespace b10k\componentmap\services;

use b10k\componentmap\models\Graph;

/**
 * Builds the map from template sources and the Craft content model.
 *
 * Craft-free: the templates arrive as `relative path => source`, the content
 * model as plain arrays (see {@see CraftStructure}), so the whole thing is
 * unit-tested without a database.
 *
 * The link between content and code that no generic tool can see is made
 * here: a dynamic include such as `'_adapters/' ~ block.type.handle ~ '.twig'`
 * resolves to every adapter file, and each file named after an entry type
 * (`_adapters/hero.twig` ↔ entry type `hero`) becomes a `renders` edge —
 * "a hero block is rendered by this template, via this dispatcher".
 */
final class GraphBuilder
{
    public function __construct(private readonly TwigScanner $scanner = new TwigScanner())
    {
    }

    /**
     * @param array<string, string> $templates relative path => Twig source
     * @param array{
     *     sections?: array<int, array{handle: string, name: string, type: string, templates: string[], entryTypes: string[]}>,
     *     entryTypes?: array<int, array{handle: string, name: string, fields: string[]}>,
     *     fields?: array<int, array{handle: string, name: string, entryTypes: string[]}>,
     *     categoryGroups?: array<int, array{handle: string, name: string, templates: string[]}>,
     * } $structure
     * @param string[] $leaves Files that can be referenced but are not scanned
     *        (SVGs pulled in with source()).
     */
    public function build(array $templates, array $structure = [], array $leaves = []): Graph
    {
        ksort($templates);
        $graph = new Graph();
        $resolver = new TemplateResolver([...array_keys($templates), ...$leaves]);

        foreach ([...array_keys($templates), ...$leaves] as $path) {
            $graph->addNode(Graph::templateId($path), 'template', $path, ['path' => $path]);
        }

        // Entry types first, so dispatch detection can find them by handle.
        $entryTypesByKey = [];
        foreach ($structure['entryTypes'] ?? [] as $type) {
            $graph->addNode('entryType:' . $type['handle'], 'entryType', $type['name'], ['handle' => $type['handle']]);
            $entryTypesByKey[self::key($type['handle'])] = $type['handle'];
        }

        foreach ($templates as $path => $source) {
            $this->addTemplateEdges($graph, $resolver, $path, $source, $entryTypesByKey);
        }

        foreach ($structure['fields'] ?? [] as $field) {
            $id = 'field:' . $field['handle'];
            $graph->addNode($id, 'field', $field['name'], ['handle' => $field['handle']]);
            foreach ($field['entryTypes'] as $handle) {
                $graph->addEdge($id, 'entryType:' . $handle, 'allows');
            }
        }

        foreach ($structure['entryTypes'] ?? [] as $type) {
            foreach ($type['fields'] as $fieldHandle) {
                $graph->addEdge('entryType:' . $type['handle'], 'field:' . $fieldHandle, 'hasField');
            }
        }

        foreach ($structure['sections'] ?? [] as $section) {
            $id = 'section:' . $section['handle'];
            $graph->addNode($id, 'section', $section['name'], ['handle' => $section['handle'], 'meta' => ['type' => $section['type']]]);
            foreach ($section['entryTypes'] as $handle) {
                $graph->addEdge($id, 'entryType:' . $handle, 'hasType');
            }
            $this->addPageTemplates($graph, $resolver, $id, $section['templates']);
        }

        foreach ($structure['categoryGroups'] ?? [] as $group) {
            $id = 'categoryGroup:' . $group['handle'];
            $graph->addNode($id, 'categoryGroup', $group['name'], ['handle' => $group['handle']]);
            $this->addPageTemplates($graph, $resolver, $id, $group['templates']);
        }

        return $graph;
    }

    /**
     * @param array<string, string> $entryTypesByKey
     */
    private function addTemplateEdges(Graph $graph, TemplateResolver $resolver, string $path, string $source, array $entryTypesByKey): void
    {
        $from = Graph::templateId($path);
        $result = $this->scanner->scan($source, $path);

        if ($result['error'] !== null) {
            $graph->addError($path, $result['error']);
        }

        foreach ($result['references'] as $reference) {
            foreach ($reference->targets as $target) {
                $pattern = $target['pattern'];

                if ($resolver->isExternal($pattern)) {
                    continue; // _self macros, @plugin templates: not part of the site
                }

                $files = $resolver->resolve($pattern);
                if ($files === []) {
                    $graph->addUnresolved(
                        $path,
                        $reference->tag,
                        $reference->line,
                        $pattern,
                        $target['expression'],
                        $target['dynamic'] ? ($pattern === '*' ? 'expression cannot be resolved statically' : 'no file matches the pattern') : 'template not found',
                    );
                    continue;
                }

                foreach ($files as $file) {
                    if ($file === $path) {
                        continue;
                    }
                    $graph->addEdge($from, Graph::templateId($file), $reference->tag, array_filter([
                        'line' => $reference->line,
                        'dynamic' => $target['dynamic'] ?: null,
                        'fallback' => $target['fallback'] ?: null,
                        'conditional' => $target['conditional'] ?: null,
                        'pattern' => $target['dynamic'] ? $pattern : null,
                    ], static fn($v) => $v !== null));

                    // Dispatcher: a dynamic include resolving to a file named
                    // after an entry type.
                    if ($target['dynamic']) {
                        $handle = $entryTypesByKey[self::key(self::baseName($file))] ?? null;
                        if ($handle !== null) {
                            $graph->addEdge('entryType:' . $handle, Graph::templateId($file), 'renders', ['via' => $path]);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string[] $templates
     */
    private function addPageTemplates(Graph $graph, TemplateResolver $resolver, string $from, array $templates): void
    {
        foreach (array_unique($templates) as $template) {
            foreach ($resolver->resolve($template) as $file) {
                $graph->addEdge($from, Graph::templateId($file), 'page');
            }
        }
    }

    private static function baseName(string $file): string
    {
        $name = basename($file);
        if ($name === 'index.twig' || $name === 'index.html') {
            $name = basename(dirname($file));
        }
        return (string)preg_replace('/\.(twig|html)$/', '', $name);
    }

    /** `cards-grid`, `cards_grid` and `cardsGrid` are the same handle. */
    private static function key(string $handle): string
    {
        return strtolower(str_replace(['-', '_'], '', $handle));
    }
}
