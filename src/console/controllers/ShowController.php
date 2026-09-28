<?php

namespace b10k\componentmap\console\controllers;

use craft\helpers\Console;

/**
 * Everything the map knows about one template, entry type, section or field.
 *
 *     php craft component-map/show _blocks/hero
 *     php craft component-map/show hero              # the entry type
 *     php craft component-map/show home --json       # the section
 */
class ShowController extends BaseController
{
    /**
     * @param string $query A template path, or an entry type / section / field handle.
     */
    public function actionIndex(string $query): int
    {
        $explorer = $this->plugin()->getMap()->explorer();
        $id = $explorer->find($query);

        if ($id === null) {
            return $this->error("Nothing called “{$query}” in the map (templates, entry types, sections, fields).", self::EXIT_NOT_FOUND);
        }

        $d = $explorer->describe($id);

        if ($this->json) {
            $this->writeJson($d);
            return self::EXIT_OK;
        }

        $node = $d['node'];
        $this->stdout("{$node['label']}", Console::BOLD);
        $this->stdout("  ({$node['kind']})\n\n", Console::FG_GREY);

        $list = function (string $title, array $items): void {
            $this->stdout("{$title}\n", Console::FG_YELLOW);
            if ($items === []) {
                $this->stdout("  —\n");
            }
            foreach ($items as $item) {
                $this->stdout("  {$item}\n");
            }
            $this->stdout("\n");
        };
        $ref = static function (array $e): string {
            $flags = array_keys(array_filter(['dynamic' => $e['dynamic'] ?? false, 'fallback' => $e['fallback'] ?? false, 'conditional' => $e['conditional'] ?? false]));
            return $e['template'] . "   {$e['tag']}" . (isset($e['line']) ? " line {$e['line']}" : '') . ($flags ? ' (' . implode(', ', $flags) . ')' : '');
        };

        switch ($node['kind']) {
            case 'template':
                $list('Used by', array_map($ref, $d['usedBy']));
                $list('Uses', array_map($ref, $d['uses']));
                if ($d['renders'] !== []) {
                    $list('Renders blocks', array_map(static fn($r) => $r['entryType'] . ($r['via'] ? "   via {$r['via']}" : ''), $d['renders']));
                }
                $list('A change affects blocks', $d['affects']['entryTypes']);
                $list('A change affects pages', array_map([self::class, 'short'], $d['affects']['pages']));
                break;
            case 'entryType':
                $list('Allowed in Matrix fields', $d['allowedIn']);
                $rendered = [];
                foreach ($d['renderedBy'] as $r) {
                    $rendered[] = $r['template'] . ($r['via'] ? "   via {$r['via']}" : '');
                    foreach ($r['uses'] as $u) {
                        $rendered[] = "  → {$u}";
                    }
                }
                $list('Rendered by', $rendered);
                $list('Its Matrix fields', $d['fields']);
                $list('On pages (sections)', array_map([self::class, 'short'], $d['pagesUsingIt']));
                break;
            case 'section':
            case 'categoryGroup':
                $tpls = [];
                foreach ($d['templates'] as $t) {
                    $tpls[] = $t['template'];
                    foreach ($t['uses'] as $u) {
                        $tpls[] = "  → {$u}";
                    }
                }
                $list('Page template', $tpls);
                $list('Entry types', $d['entryTypes'] ?? []);
                break;
            case 'field':
                $list('Allows blocks', $d['allows']);
                $list('On entry types', $d['onEntryTypes']);
                break;
        }

        return self::EXIT_OK;
    }
}
