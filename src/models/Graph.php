<?php

namespace b10k\componentmap\models;

/**
 * The map: templates, sections, Matrix fields and entry types, and how they
 * connect.
 *
 * Node ids are prefixed by kind — `template:_blocks/hero.twig`,
 * `section:home`, `field:contentBlocks`, `entryType:hero` — so one id space
 * holds code and content.
 *
 * Edge kinds:
 *
 * - between templates: `include`, `embed`, `extends`, `import`, `from`, `use`,
 *   `include()`, `source()` — "from uses to";
 * - `page`: a section renders its entries with this template;
 * - `allows`: a Matrix field accepts this entry type;
 * - `renders`: a block of this entry type is rendered by this template,
 *   reached through a dynamic include in `via` (the dispatcher).
 */
final class Graph
{
    /** @var array<string, array{id: string, kind: string, label: string, handle?: string, path?: string, meta?: array<string, mixed>}> */
    private array $nodes = [];

    /** @var array<string, array{from: string, to: string, kind: string, line?: int, dynamic?: bool, fallback?: bool, conditional?: bool, pattern?: string, via?: string}> */
    private array $edges = [];

    /** @var array<int, array{from: string, tag: string, line: int, pattern: string, expression: string, reason: string}> */
    private array $unresolved = [];

    /** @var array<string, string> template path → error */
    private array $errors = [];

    /** @var array<string, array<string, true>> */
    private array $out = [];

    /** @var array<string, array<string, true>> */
    private array $in = [];

    public static function templateId(string $path): string
    {
        return 'template:' . $path;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function addNode(string $id, string $kind, string $label, array $extra = []): void
    {
        $this->nodes[$id] = array_merge($this->nodes[$id] ?? [], ['id' => $id, 'kind' => $kind, 'label' => $label], $extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function addEdge(string $from, string $to, string $kind, array $extra = []): void
    {
        $key = "{$from}|{$to}|{$kind}";
        if (isset($this->edges[$key])) {
            // The same file reached twice (a dynamic pattern and a fallback):
            // keep one edge that carries both flags.
            foreach ($extra as $k => $v) {
                if ($v === true || !isset($this->edges[$key][$k])) {
                    $this->edges[$key][$k] = $v;
                }
            }
            return;
        }
        $this->edges[$key] = array_merge(['from' => $from, 'to' => $to, 'kind' => $kind], $extra);
        $this->out[$from][$key] = true;
        $this->in[$to][$key] = true;
    }

    public function addUnresolved(string $from, string $tag, int $line, string $pattern, string $expression, string $reason): void
    {
        $this->unresolved[] = compact('from', 'tag', 'line', 'pattern', 'expression', 'reason');
    }

    public function addError(string $template, string $message): void
    {
        $this->errors[$template] = $message;
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /**
     * @return array{id: string, kind: string, label: string}|null
     */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * @return array<string, array{id: string, kind: string, label: string}>
     */
    public function nodes(?string $kind = null): array
    {
        $nodes = $kind === null ? $this->nodes : array_filter($this->nodes, static fn(array $n) => $n['kind'] === $kind);
        ksort($nodes);
        return $nodes;
    }

    /**
     * @param string[]|null $kinds
     * @return array<int, array{from: string, to: string, kind: string}>
     */
    public function outgoing(string $id, ?array $kinds = null): array
    {
        return $this->edgesFor($this->out[$id] ?? [], $kinds);
    }

    /**
     * @param string[]|null $kinds
     * @return array<int, array{from: string, to: string, kind: string}>
     */
    public function incoming(string $id, ?array $kinds = null): array
    {
        return $this->edgesFor($this->in[$id] ?? [], $kinds);
    }

    /**
     * Everything `$id` pulls in, directly or not, following template edges.
     *
     * @return string[]
     */
    public function descendants(string $id): array
    {
        return $this->walk($id, fn(string $n) => array_column($this->outgoing($n, self::TEMPLATE_EDGES), 'to'));
    }

    /**
     * Every template that pulls `$id` in, directly or not.
     *
     * @return string[]
     */
    public function ancestors(string $id): array
    {
        return $this->walk($id, fn(string $n) => array_column($this->incoming($n, self::TEMPLATE_EDGES), 'from'));
    }

    /** Template-to-template edge kinds. */
    public const TEMPLATE_EDGES = ['include', 'embed', 'extends', 'import', 'from', 'use', 'include()', 'source()'];

    /**
     * @return array<int, array{from: string, tag: string, line: int, pattern: string, expression: string, reason: string}>
     */
    public function unresolved(): array
    {
        return $this->unresolved;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        ksort($this->errors);
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $edges = array_values($this->edges);
        usort($edges, static fn(array $a, array $b) => [$a['from'], $a['to'], $a['kind']] <=> [$b['from'], $b['to'], $b['kind']]);

        return [
            'nodes' => array_values($this->nodes()),
            'edges' => $edges,
            'unresolved' => $this->unresolved,
            'errors' => $this->errors(),
        ];
    }

    /**
     * @param array<string, true> $keys
     * @param string[]|null $kinds
     * @return array<int, array{from: string, to: string, kind: string}>
     */
    private function edgesFor(array $keys, ?array $kinds): array
    {
        $edges = [];
        foreach (array_keys($keys) as $key) {
            $edge = $this->edges[$key];
            if ($kinds === null || in_array($edge['kind'], $kinds, true)) {
                $edges[] = $edge;
            }
        }
        usort($edges, static fn(array $a, array $b) => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);
        return $edges;
    }

    /**
     * @param callable(string): string[] $next
     * @return string[]
     */
    private function walk(string $start, callable $next): array
    {
        $seen = [];
        $queue = [$start];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($next($current) as $n) {
                if ($n !== $start && !isset($seen[$n])) {
                    $seen[$n] = true;
                    $queue[] = $n;
                }
            }
        }
        $ids = array_keys($seen);
        sort($ids);
        return $ids;
    }
}
