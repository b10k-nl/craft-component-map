<?php

namespace b10k\componentmap\services;

use b10k\componentmap\Plugin;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;

/**
 * The reports the console commands print and the MCP tools return — one
 * shape for both, so `--json` and an agent see the same thing.
 */
class Reports extends Component
{
    /** Why entries could not be read on the last call, if they could not. */
    public ?string $entriesError = null;

    /**
     * Forget the map and the entries read so far. Craft's MCP server over
     * stdio is one long process: without this an agent would get the map as
     * it was at its first call.
     */
    public function fresh(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->getMap()->reset();
        $plugin->getCraftContent()->reset();
        $this->entriesError = null;
    }

    /**
     * What changing these files affects.
     *
     * @param string[] $paths Project- or templates-relative.
     * @param int $limit Entries to describe; 0 = all.
     * @param bool $forViewer Leave out entries the current user may not view (they are still counted).
     * @return array<string, mixed>
     * @throws ReportException
     */
    public function impact(array $paths, bool $git = false, string $since = '', int $limit = 0, bool $forViewer = false): array
    {
        $map = Plugin::getInstance()->getMap();
        $since = trim($since);
        $paths = array_values(array_filter(array_map('trim', $paths), static fn($p) => $p !== ''));
        $deleted = [];
        $base = null;

        if ($git || $since !== '') {
            try {
                $changes = $map->gitChanges($since !== '' ? $since : null);
            } catch (\RuntimeException $e) {
                throw new ReportException($e->getMessage(), 0, $e);
            }
            if ($changes === null) {
                throw new ReportException('Not a git checkout (or git is not available here).');
            }
            $paths = [...$paths, ...$changes['changed']];
            $deleted = $changes['deleted'];
            $base = $changes['base'];
        } elseif ($paths === []) {
            throw new ReportException('Name the files, or use git / since.');
        }

        $changed = $map->classify($paths);
        $gone = $map->classify($deleted);
        $impact = $map->explorer()->impact(
            $changed['templates'],
            $gone['templates'],
            [...$changed['contentModel'], ...$gone['contentModel']],
        );
        $impact['ignored'] = array_values(array_unique([...$changed['ignored'], ...$gone['ignored']]));
        $impact['unmapped'] = array_values(array_unique([...$impact['unmapped'], ...$changed['other'], ...$gone['other']]));

        $entries = null;
        $index = $this->content();
        if ($index !== null) {
            $wholeSections = array_map([self::class, 'short'], array_filter($impact['wholePages'], static fn($p) => str_starts_with($p, 'section:')));
            $entries = $this->entries($index->toCheck($impact['entryTypes'], $wholeSections), $limit, $forViewer);
        }

        return ['status' => 'ok', 'since' => $since !== '' ? $since : null, 'base' => $base, 'files' => $paths, 'deletedFiles' => $deleted]
            + $impact
            + ['entriesToCheck' => $entries];
    }

    /**
     * Everything the map knows about one template, entry type, section or
     * field. Null when nothing has that name.
     *
     * @return array<string, mixed>|null
     */
    public function show(string $query, int $limit = 0, bool $forViewer = false): ?array
    {
        $explorer = Plugin::getInstance()->getMap()->explorer();
        $id = $explorer->find($query);
        if ($id === null) {
            return null;
        }

        $d = $explorer->describe($id);
        $d['entries'] = null;
        $index = $this->content();
        if ($index !== null) {
            $handle = self::short($id);
            $ids = match ($d['node']['kind']) {
                'entryType' => $d['allowedIn'] !== [] ? $index->containing($handle) : $index->ofType($handle),
                'section' => $index->inSection($handle),
                default => null,
            };
            if ($ids !== null) {
                $d['entries'] = $this->entries(array_fill_keys($ids, []), $limit, $forViewer);
            }
        }
        return $d;
    }

    /**
     * @return array<string, mixed>
     */
    public function unused(): array
    {
        $plugin = Plugin::getInstance();
        $usage = $this->content()?->usage();
        return $plugin->getMap()->explorer()->unused($usage, $plugin->getSettings()->entryPoints);
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(int $limit = 50): array
    {
        $usage = $this->content()?->usage();
        return Plugin::getInstance()->getMap()->explorer()->overview($usage, $limit);
    }

    /**
     * Words in the commands' and tools' output name nodes without their kind:
     * `section:home` → `home`.
     */
    public static function short(string $id): string
    {
        $pos = strpos($id, ':');
        return $pos === false ? $id : substr($id, $pos + 1);
    }

    private function content(): ?ContentIndex
    {
        $plugin = Plugin::getInstance();
        if (!$plugin->getSettings()->readEntries) {
            return null;
        }
        try {
            return $plugin->getCraftContent()->index();
        } catch (\Throwable $e) {
            $this->entriesError = $e->getMessage();
            return null;
        }
    }

    /**
     * Entries, most reasons first, with title, URL and status.
     *
     * @param array<int, string[]> $reasons element id => reasons
     * @return array{total: int, entries: array<int, array<string, mixed>>, hidden?: int}|null
     */
    private function entries(array $reasons, int $limit, bool $forViewer): ?array
    {
        uksort($reasons, static fn(int $a, int $b) => [count($reasons[$b]), $a] <=> [count($reasons[$a]), $b]);
        $ids = $limit > 0 ? array_slice(array_keys($reasons), 0, $limit) : array_keys($reasons);

        $visible = null;
        if ($forViewer) {
            $user = Craft::$app->getUser()->getIdentity();
            $elements = Craft::$app->getElements();
            $visible = static fn(ElementInterface $e): bool => $user !== null && $elements->canView($e, $user);
        }

        try {
            $entries = Plugin::getInstance()->getCraftContent()->describe($ids, $visible);
        } catch (\Throwable $e) {
            $this->entriesError = $e->getMessage();
            return null;
        }
        foreach ($entries as &$entry) {
            $entry['reasons'] = $reasons[$entry['id']] ?? [];
        }
        unset($entry);

        $out = ['total' => count($reasons), 'entries' => $entries];
        if ($forViewer) {
            $out['hidden'] = count($ids) - count($entries);
        }
        return $out;
    }
}
