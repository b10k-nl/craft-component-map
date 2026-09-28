<?php

namespace b10k\componentmap\console\controllers;

use craft\helpers\Console;

/**
 * What changing these files affects: templates above them, page-builder
 * blocks rendered through them, pages that reach them.
 *
 *     php craft component-map/impact templates/_components/card.twig
 *     php craft component-map/impact _components/card,_blocks/hero
 *     php craft component-map/impact --git            # uncommitted changes
 *     php craft component-map/impact --git --json
 *
 * The `entryTypes` it reports are the component handles Component Check
 * tests: `component-check/test $(…)`.
 */
class ImpactController extends BaseController
{
    /**
     * @var bool Use the files git reports as changed (staged, unstaged, untracked).
     */
    public bool $git = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['git']);
    }

    /**
     * @param string $files Comma-separated paths (project- or templates-relative).
     */
    public function actionIndex(string $files = ''): int
    {
        $map = $this->plugin()->getMap();

        $paths = array_values(array_filter(array_map('trim', explode(',', $files)), static fn($p) => $p !== ''));
        if ($this->git) {
            $changed = $map->gitChangedFiles();
            if ($changed === null) {
                return $this->error('Not a git checkout (or git is not available here).');
            }
            $paths = [...$paths, ...$changed];
        }
        if ($paths === []) {
            return $this->error('Name the files, or use --git.', self::EXIT_ERROR);
        }

        $impact = $map->explorer()->impact($map->toTemplatePaths($paths));

        if ($this->json) {
            $this->writeJson(['status' => 'ok', 'files' => $paths] + $impact);
            return self::EXIT_OK;
        }

        $section = function (string $title, array $items): void {
            $this->stdout("{$title}\n", Console::FG_YELLOW);
            $this->stdout($items === [] ? "  —\n" : '  ' . implode("\n  ", $items) . "\n");
            $this->stdout("\n");
        };

        $section('Blocks (entry types) affected', $impact['entryTypes']);
        $section('Pages affected', array_map([self::class, 'short'], $impact['pages']));
        $section('Templates affected', $impact['templates']);
        if ($impact['unmapped'] !== []) {
            $section('Not templates (CSS, JS, PHP…) — may affect anything', $impact['unmapped']);
        }

        if ($impact['entryTypes'] !== []) {
            $this->stdout('Test them: php craft component-check/test ' . implode(',', $impact['entryTypes']) . "\n", Console::FG_GREY);
        }

        return self::EXIT_OK;
    }
}
