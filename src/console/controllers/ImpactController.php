<?php

namespace b10k\componentmap\console\controllers;

use b10k\componentmap\services\ReportException;
use Craft;
use craft\helpers\Console;

/**
 * What changing these files affects: templates above them, page-builder
 * blocks rendered through them, pages that reach them.
 *
 *     php craft component-map/impact templates/_components/card.twig
 *     php craft component-map/impact _components/card,_blocks/hero
 *     php craft component-map/impact --git            # uncommitted changes
 *     php craft component-map/impact --since=main     # the whole branch, committed or not
 *     php craft component-map/impact --since=main --json
 *
 * A deleted template counts through the templates that still reference it.
 */
class ImpactController extends BaseController
{
    /**
     * @var bool Use the files git reports as changed (staged, unstaged, untracked).
     */
    public bool $git = false;

    /**
     * @var string A branch, tag or commit: use everything that changed since this
     * branch left it — commits on this branch plus uncommitted changes.
     */
    public string $since = '';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['git', 'since', 'limit']);
    }

    /**
     * @param string $files Comma-separated paths (project- or templates-relative).
     */
    public function actionIndex(string $files = ''): int
    {
        $since = trim($this->since);
        $reports = $this->plugin()->getReports();
        try {
            $report = $reports->impact(explode(',', $files), $this->git, $since, $this->json ? 0 : $this->limit);
        } catch (ReportException $e) {
            return $this->error($e->getMessage());
        }
        $this->warnEntries($reports->entriesError);

        if ($this->json) {
            $this->writeJson($report);
            return self::EXIT_OK;
        }

        if ($report['files'] === [] && $report['deletedFiles'] === []) {
            $this->stdout(($since !== '' ? "Nothing changed since {$since}." : 'No uncommitted changes.') . "\n");
            return self::EXIT_OK;
        }

        $base = $report['base'];
        $impact = $report;
        $entries = $report['entriesToCheck'];
        $map = $this->plugin()->getMap();

        if ($since !== '') {
            $this->stdout("Changes since {$since}" . ($base ? ' (from ' . substr($base, 0, 7) . ')' : '') . ", committed or not\n\n", Console::FG_GREY);
        }

        $section = function (string $title, array $items, ?int $color = Console::FG_YELLOW): void {
            $this->stdout("{$title}\n", $color);
            $this->stdout($items === [] ? "  —\n" : '  ' . implode("\n  ", $items) . "\n");
            $this->stdout("\n");
        };

        $section('Blocks (entry types) affected', $impact['entryTypes']);
        $whole = array_flip($impact['wholePages']);
        $section('Pages affected', array_map(static fn($p) => self::short($p) . (isset($whole[$p]) ? '   (every entry)' : '   (entries with these blocks)'), $impact['pages']));
        if ($entries !== null) {
            $this->printEntries('Entries to check', $entries);
        }
        $section('Templates affected', $impact['templates']);
        if ($impact['contentModel'] !== []) {
            $kinds = ['entryType' => 'entry type', 'field' => 'field', 'section' => 'section', 'categoryGroup' => 'category group'];
            $section('Content model changed (project config)', array_map(static function(array $c) use ($kinds): string {
                [$kind, $handle] = explode(':', $c['id'], 2);
                return ($kinds[$kind] ?? $kind) . " {$handle}" . ($c['inMap'] ? '' : ($kind === 'field' ? '   (not a Matrix field — not in the map)' : '   (not in the map)'));
            }, $impact['contentModel']));
        }
        if ($impact['deleted'] !== []) {
            $section('Deleted templates — whatever still references them now breaks', $impact['deleted']);
        }
        if ($impact['ignored'] !== []) {
            $section('Templates left out of the map (ignore setting)', $impact['ignored'], Console::FG_GREY);
        }
        if ($impact['unmapped'] !== []) {
            $section('Other files — not analysed (CSS, JS and PHP can affect any page)', $impact['unmapped'], Console::FG_GREY);
        }

        if ($impact['entryTypes'] !== [] && Craft::$app->getPlugins()->isPluginEnabled('component-check')) {
            $this->stdout('Test them: ' . $map->craftCommand() . ' component-check/test ' . implode(',', $impact['entryTypes']) . "\n", Console::FG_GREY);
        }

        return self::EXIT_OK;
    }
}
