<?php

namespace b10k\componentmap\services;

use b10k\componentmap\models\Graph;

/**
 * Questions asked of the map: "where is this used?", "what does it use?",
 * and "what does changing these files affect?".
 *
 * Craft-free.
 */
final class Explorer
{
    public function __construct(private readonly Graph $graph)
    {
    }

    /**
     * Turns what a person or agent typed into a node id: a template path
     * (`_blocks/hero.twig`, `_blocks/hero`, `templates/_blocks/hero.twig`),
     * or a handle of an entry type, section or field.
     */
    public function find(string $query): ?string
    {
        $query = trim($query);
        if ($this->graph->hasNode($query)) {
            return $query;
        }

        $path = preg_replace('#^/?templates/#', '', ltrim($query, '/')) ?? $query;
        $resolver = new TemplateResolver(array_map(
            static fn(array $n) => (string)substr($n['id'], strlen('template:')),
            $this->graph->nodes('template'),
        ));
        $files = $resolver->resolve($path);
        if ($files !== []) {
            return Graph::templateId($files[0]);
        }

        foreach (['entryType', 'section', 'field', 'categoryGroup'] as $kind) {
            if ($this->graph->hasNode("{$kind}:{$query}")) {
                return "{$kind}:{$query}";
            }
        }
        return null;
    }

    /**
     * Everything worth knowing about one node.
     *
     * @return array<string, mixed>
     */
    public function describe(string $id): array
    {
        $node = $this->graph->node($id) ?? ['id' => $id, 'kind' => 'unknown', 'label' => $id];
        $out = ['node' => $node];

        if ($node['kind'] === 'template') {
            $out['uses'] = array_map(fn(array $e) => $this->edgeSummary($e, 'to'), $this->graph->outgoing($id, Graph::TEMPLATE_EDGES));
            $out['usedBy'] = array_map(fn(array $e) => $this->edgeSummary($e, 'from'), $this->graph->incoming($id, Graph::TEMPLATE_EDGES));
            $out['renders'] = array_map(
                fn(array $e) => ['entryType' => $this->handle($e['from']), 'via' => $e['via'] ?? null],
                $this->graph->incoming($id, ['renders']),
            );
            $impact = $this->impact([$this->path($id)]);
            $out['affects'] = ['entryTypes' => $impact['entryTypes'], 'pages' => $impact['pages']];
            return $out;
        }

        if ($node['kind'] === 'entryType') {
            $out['allowedIn'] = array_map(fn(array $e) => $this->handle($e['from']), $this->graph->incoming($id, ['allows']));
            $out['renderedBy'] = [];
            foreach ($this->graph->outgoing($id, ['renders']) as $e) {
                $out['renderedBy'][] = [
                    'template' => $this->path($e['to']),
                    'via' => $e['via'] ?? null,
                    'uses' => array_map(fn(string $t) => $this->path($t), $this->graph->descendants($e['to'])),
                ];
            }
            $out['fields'] = array_map(fn(array $e) => $this->handle($e['to']), $this->graph->outgoing($id, ['hasField']));
            $pagesUsingIt = $this->upFrom($id)[1];
            sort($pagesUsingIt);
            $out['pagesUsingIt'] = $pagesUsingIt;
            return $out;
        }

        if ($node['kind'] === 'section' || $node['kind'] === 'categoryGroup') {
            $out['templates'] = [];
            foreach ($this->graph->outgoing($id, ['page']) as $e) {
                $out['templates'][] = [
                    'template' => $this->path($e['to']),
                    'uses' => array_map(fn(string $t) => $this->path($t), $this->graph->descendants($e['to'])),
                ];
            }
            $out['entryTypes'] = array_map(fn(array $e) => $this->handle($e['to']), $this->graph->outgoing($id, ['hasType']));
            return $out;
        }

        if ($node['kind'] === 'field') {
            $out['allows'] = array_map(fn(array $e) => $this->handle($e['to']), $this->graph->outgoing($id, ['allows']));
            $out['onEntryTypes'] = array_map(fn(array $e) => $this->handle($e['from']), $this->graph->incoming($id, ['hasField']));
        }

        return $out;
    }

    /**
     * What changing these template files affects.
     *
     * - templates: the files themselves and every template that pulls them in;
     * - entryTypes: block types whose rendering goes through one of the files —
     *   the adapter, anything it includes, and the dispatcher and layouts
     *   above it;
     * - pages: sections and category groups whose page template reaches one of
     *   the files.
     *
     * Files that are not templates in the map are returned as `unmapped`.
     *
     * A deleted template is no longer in the map, but the templates that still
     * reference it are — as unresolved references. Those templates count as
     * changed: they now break, and so does everything above them.
     *
     * A change to the content model (an entry type, Matrix field, section or
     * category group in project config) affects that block, the blocks it is
     * nested in, and the pages that end up showing it.
     *
     * @param string[] $paths Paths relative to the templates folder.
     * @param string[] $deleted Deleted files, relative to the templates folder.
     * @param string[] $contentModel Node ids of changed content model items: `entryType:hero`, `field:gridCards`.
     * @return array{templates: string[], entryTypes: string[], pages: string[], contentModel: array<int, array{id: string, inMap: bool}>, deleted: string[], unmapped: string[]}
     */
    public function impact(array $paths, array $deleted = [], array $contentModel = []): array
    {
        $changed = [];
        $unmapped = [];
        foreach ($paths as $path) {
            $id = Graph::templateId(ltrim($path, '/'));
            if ($this->graph->hasNode($id)) {
                $changed[$id] = true;
            } else {
                $unmapped[] = $path;
            }
        }

        $deletedTemplates = [];
        foreach ($deleted as $path) {
            $path = ltrim($path, '/');
            $names = self::namesFor($path);
            if ($names === []) {
                $unmapped[] = $path;
                continue;
            }
            $deletedTemplates[] = $path;
            foreach ($this->graph->unresolved() as $u) {
                $from = Graph::templateId($u['from']);
                if (in_array(ltrim($u['pattern'], '/'), $names, true) && $this->graph->hasNode($from)) {
                    $changed[$from] = true;
                }
            }
        }
        sort($deletedTemplates);

        $templates = $changed;
        foreach (array_keys($changed) as $id) {
            foreach ($this->graph->ancestors($id) as $a) {
                $templates[$a] = true;
            }
        }

        $entryTypes = [];
        foreach ($this->graph->nodes('entryType') as $typeId => $_) {
            foreach ($this->graph->outgoing($typeId, ['renders']) as $e) {
                if (array_intersect_key($this->renderSet($e['to']), $changed) !== []) {
                    $entryTypes[$this->handle($typeId)] = true;
                }
            }
        }

        $pages = [];
        foreach ([...$this->graph->nodes('section'), ...$this->graph->nodes('categoryGroup')] as $pageId => $_) {
            foreach ($this->graph->outgoing($pageId, ['page']) as $e) {
                $reach = array_fill_keys([$e['to'], ...$this->graph->descendants($e['to'])], true);
                if (array_intersect_key($reach, $changed) !== []) {
                    $pages[$pageId] = true;
                }
            }
        }

        $modelChanges = [];
        foreach (array_values(array_unique($contentModel)) as $id) {
            $inMap = $this->graph->hasNode($id);
            $modelChanges[] = ['id' => $id, 'inMap' => $inMap];
            if (!$inMap) {
                continue;
            }
            $kind = substr($id, 0, (int)strpos($id, ':'));
            $types = match ($kind) {
                'entryType' => [$id],
                'field' => array_column($this->graph->incoming($id, ['hasField']), 'from'),
                default => [],
            };
            if ($kind === 'section' || $kind === 'categoryGroup') {
                $pages[$id] = true;
            }
            foreach ($types as $typeId) {
                [$blocks, $typePages] = $this->upFrom($typeId);
                foreach ($blocks as $b) {
                    $entryTypes[$this->handle($b)] = true;
                }
                foreach ($typePages as $p) {
                    $pages[$p] = true;
                }
            }
        }
        usort($modelChanges, static fn($a, $b) => strcmp($a['id'], $b['id']));

        $templatePaths = array_map(fn(string $id) => $this->path($id), array_keys($templates));
        sort($templatePaths);
        $entryTypeHandles = array_keys($entryTypes);
        sort($entryTypeHandles);
        $pageIds = array_keys($pages);
        sort($pageIds);

        return [
            'templates' => $templatePaths,
            'entryTypes' => $entryTypeHandles,
            'pages' => $pageIds,
            'contentModel' => $modelChanges,
            'deleted' => $deletedTemplates,
            'unmapped' => $unmapped,
        ];
    }

    /**
     * The names a template can be referenced by, as Craft resolves them:
     * `_x/card.twig` is `_x/card.twig` or `_x/card`; `_x/index.twig` is also
     * `_x`. Empty for files that are not templates.
     *
     * @return string[]
     */
    private static function namesFor(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['twig', 'html'], true)) {
            return [];
        }
        $bare = substr($path, 0, -strlen($ext) - 1);
        $names = [$path, $bare];
        if (basename($bare) === 'index') {
            $dir = dirname($bare);
            $names[] = $dir === '.' ? '' : $dir;
        }
        return $names;
    }

    /**
     * The templates involved in rendering one block through `$adapter`.
     *
     * @return array<string, true>
     */
    private function renderSet(string $adapter): array
    {
        $set = [$adapter => true];
        foreach ($this->graph->descendants($adapter) as $d) {
            $set[$d] = true;
        }
        foreach ($this->graph->ancestors($adapter) as $a) {
            $set[$a] = true;
            // Layouts the page extends wrap the block too.
            foreach ($this->graph->outgoing($a, ['extends']) as $e) {
                $set[$e['to']] = true;
                foreach ($this->graph->descendants($e['to']) as $d) {
                    $set[$d] = true;
                }
            }
        }
        return $set;
    }

    /**
     * @return string[] section:… ids
     */
    /**
     * From an entry type up through the content model: the blocks it is or is
     * nested in (entry types allowed in a Matrix field), and the sections and
     * category groups whose entries end up containing it.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function upFrom(string $typeId): array
    {
        $blocks = [];
        $pages = [];
        $seen = [];
        $queue = [$typeId];
        while ($queue !== []) {
            $type = array_shift($queue);
            if (isset($seen[$type])) {
                continue;
            }
            $seen[$type] = true;
            foreach ($this->graph->incoming($type, ['hasType']) as $e) {
                $pages[$e['from']] = true;
            }
            $allowedIn = $this->graph->incoming($type, ['allows']);
            if ($allowedIn !== []) {
                $blocks[$type] = true;
            }
            foreach ($allowedIn as $allows) {
                foreach ($this->graph->incoming($allows['from'], ['hasField']) as $hasField) {
                    $queue[] = $hasField['from'];
                }
            }
        }
        return [array_keys($blocks), array_keys($pages)];
    }

    /**
     * The content model item a project config file describes —
     * `config/project/entryTypes/hero--<uid>.yaml` is `entryType:hero`.
     * Null for other files (including `project.yaml` itself).
     *
     * @param string $path Project-relative path.
     * @param string $configDir Project config folder, project-relative.
     */
    public static function contentModelItem(string $path, string $configDir = 'config/project'): ?string
    {
        $prefix = trim(str_replace('\\', '/', $configDir), '/') . '/';
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($path, $prefix)
            || !preg_match('#^(entryTypes|fields|sections|categoryGroups)/([A-Za-z0-9_-]+?)--[0-9a-f-]{36}\.yaml$#', substr($path, strlen($prefix)), $m)) {
            return null;
        }
        $kind = ['entryTypes' => 'entryType', 'fields' => 'field', 'sections' => 'section', 'categoryGroups' => 'categoryGroup'][$m[1]];
        return "{$kind}:{$m[2]}";
    }

    /**
     * @param array<string, mixed> $edge
     * @return array<string, mixed>
     */
    private function edgeSummary(array $edge, string $side): array
    {
        return array_filter([
            'template' => $this->path($edge[$side]),
            'tag' => $edge['kind'],
            'line' => $edge['line'] ?? null,
            'dynamic' => $edge['dynamic'] ?? null,
            'fallback' => $edge['fallback'] ?? null,
            'conditional' => $edge['conditional'] ?? null,
            'pattern' => $edge['pattern'] ?? null,
        ], static fn($v) => $v !== null);
    }

    private function path(string $id): string
    {
        return str_starts_with($id, 'template:') ? substr($id, strlen('template:')) : $id;
    }

    private function handle(string $id): string
    {
        $pos = strpos($id, ':');
        return $pos === false ? $id : substr($id, $pos + 1);
    }
}
