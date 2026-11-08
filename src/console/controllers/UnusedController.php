<?php

namespace b10k\componentmap\console\controllers;

use craft\helpers\Console;

/**
 * What nothing uses: blocks no entry contains, templates nothing reaches,
 * entry types and Matrix fields that are not attached anywhere.
 *
 *     php craft component-map/unused
 *     php craft component-map/unused --json
 *
 * Read it as a list of candidates, not a delete list: a template rendered from
 * PHP, `config/routes.php` or a plugin setting is invisible to the map — add it
 * to the `entryPoints` setting once you have checked it.
 */
class UnusedController extends BaseController
{
    public function actionIndex(): int
    {
        $reports = $this->plugin()->getReports();
        $report = $reports->unused();
        $this->warnEntries($reports->entriesError);

        if ($this->json) {
            $this->writeJson(['status' => 'ok'] + $report);
            return self::EXIT_OK;
        }

        $section = function (string $title, array $rows): void {
            $this->stdout("{$title} (" . count($rows) . ")\n", Console::FG_YELLOW);
            $this->stdout($rows === [] ? "  —\n" : '');
            $width = max(array_map(static fn(array $r) => mb_strlen($r[0]), $rows) ?: [0]);
            foreach ($rows as [$main, $note]) {
                $this->stdout('  ' . $main);
                if ($note !== '') {
                    $this->stdout(str_repeat(' ', $width - mb_strlen($main)) . "   {$note}", Console::FG_GREY);
                }
                $this->stdout("\n");
            }
            $this->stdout("\n");
        };

        if ($report['blocks'] !== null) {
            $section('Blocks no entry uses', array_map(
                static fn(array $b) => [$b['handle'], 'allowed in ' . implode(', ', $b['allowedIn'])],
                $report['blocks'],
            ));
        } else {
            $this->stdout("Blocks no entry uses\n", Console::FG_YELLOW);
            $this->stdout("  not checked — reading entries is off (readEntries)\n\n", Console::FG_GREY);
        }

        $section('Templates nothing reaches', array_map(
            static fn(array $t) => [$t['template'], $t['usedBy'] === [] ? 'not referenced' : 'only from ' . implode(', ', $t['usedBy'])],
            $report['templates'],
        ));
        $section('Entry types in no section or Matrix field', array_map(static fn($h) => [$h, ''], $report['entryTypes']));
        $section('Matrix fields on no entry type', array_map(static fn($h) => [$h, ''], $report['fields']));

        if ($report['runtime'] !== []) {
            $this->stdout("Chosen at runtime — any template above might be used through these:\n", Console::FG_YELLOW);
            foreach ($report['runtime'] as $r) {
                $this->stdout("  {$r['template']} line {$r['line']}   {$r['expression']}\n");
            }
            $this->stdout("\n");
        }

        $this->stdout("Candidates, not a delete list: templates rendered from PHP, config/routes.php or a plugin\n", Console::FG_GREY);
        $this->stdout("are not seen. Once checked, list them in the entryPoints setting.\n", Console::FG_GREY);

        return self::EXIT_OK;
    }
}
