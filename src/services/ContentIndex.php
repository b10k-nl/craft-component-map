<?php

namespace b10k\componentmap\services;

/**
 * Which entries contain which blocks — read from how Craft stores Matrix
 * content: every block is a nested entry with one or more owners, and owners
 * can be blocks themselves (a Card inside a Cards Grid inside a page).
 *
 * Built from two lists, both canonical content only (no drafts, revisions or
 * deleted elements):
 *
 * - nested: block id => its type handle and the ids of its owners;
 * - pages: top-level entry id => its section and entry type handles.
 *
 * Anything that owns blocks and is not a block itself counts as a page, so
 * blocks on a category or a global set are found too.
 *
 * Craft-free.
 */
final class ContentIndex
{
    /** @var array<int, int[]> memo: element id => top-level owner ids */
    private array $tops = [];

    /**
     * @param array<int, array{type: string, owners: int[]}> $nested
     * @param array<int, array{section: string, type: string}> $pages
     */
    public function __construct(
        private readonly array $nested,
        private readonly array $pages,
    ) {
    }

    /**
     * Top-level elements that contain a block of this type, at any depth.
     *
     * @return int[]
     */
    public function containing(string $blockType): array
    {
        $out = [];
        foreach ($this->nested as $id => $block) {
            if ($block['type'] === $blockType) {
                foreach ($this->topsOf($id) as $top) {
                    $out[$top] = true;
                }
            }
        }
        return $this->sorted($out);
    }

    /**
     * Entries of a section.
     *
     * @return int[]
     */
    public function inSection(string $section): array
    {
        $out = [];
        foreach ($this->pages as $id => $page) {
            if ($page['section'] === $section) {
                $out[$id] = true;
            }
        }
        return $this->sorted($out);
    }

    /**
     * Entries whose own type is this one (for page entry types).
     *
     * @return int[]
     */
    public function ofType(string $type): array
    {
        $out = [];
        foreach ($this->pages as $id => $page) {
            if ($page['type'] === $type) {
                $out[$id] = true;
            }
        }
        return $this->sorted($out);
    }

    /**
     * Number of top-level elements each block type appears on.
     *
     * @return array<string, int>
     */
    public function usage(): array
    {
        $tops = [];
        foreach ($this->nested as $id => $block) {
            foreach ($this->topsOf($id) as $top) {
                $tops[$block['type']][$top] = true;
            }
        }
        $out = array_map('count', $tops);
        ksort($out);
        return $out;
    }

    /**
     * The entries to check for an impact: those that contain an affected
     * block, and every entry of a section affected as a whole. With the
     * reasons — block handles, or `page` for the whole-section case.
     *
     * @param string[] $blockTypes
     * @param string[] $wholeSections Section handles.
     * @return array<int, string[]> element id => reasons
     */
    public function toCheck(array $blockTypes, array $wholeSections): array
    {
        $out = [];
        foreach ($wholeSections as $section) {
            foreach ($this->inSection($section) as $id) {
                $out[$id][] = 'page';
            }
        }
        foreach ($blockTypes as $type) {
            foreach ($this->containing($type) as $id) {
                $out[$id][] = $type;
            }
        }
        ksort($out);
        return array_map(static fn(array $r) => array_values(array_unique($r)), $out);
    }

    /**
     * @param array<int, true> $path
     * @return int[]
     */
    private function topsOf(int $id, array $path = []): array
    {
        if (isset($this->tops[$id])) {
            return $this->tops[$id];
        }
        if (!isset($this->nested[$id])) {
            return [$id]; // not a block: a page (or another element that owns blocks)
        }
        if (isset($path[$id])) {
            return []; // an ownership cycle — should not exist, never loop on it
        }
        $path[$id] = true;
        $out = [];
        foreach ($this->nested[$id]['owners'] as $owner) {
            foreach ($this->topsOf($owner, $path) as $top) {
                $out[$top] = true;
            }
        }
        return $this->tops[$id] = $this->sorted($out);
    }

    /**
     * @param array<int, true> $set
     * @return int[]
     */
    private function sorted(array $set): array
    {
        $ids = array_keys($set);
        sort($ids);
        return $ids;
    }
}
