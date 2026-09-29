<?php

namespace b10k\componentmap\console\controllers;

use craft\helpers\Console;

/**
 * Component Map — what the commands are.
 *
 *     component-map/show <template|handle>  Where is it used, what does it use
 *     component-map/impact <files> | --git  What a change affects
 *     component-map/build                 Snapshot of the whole map in storage/
 *     component-map/agents                The note for AGENTS.md / CLAUDE.md
 */
class DefaultController extends BaseController
{
    public function actionIndex(): int
    {
        $this->stdout("Component Map\n\n", Console::BOLD);
        foreach ([
            ['show', '<template|handle>', 'Where a template, block or section is used, and what it uses'],
            ['impact', '<files> | --git', 'What changing these files affects'],
            ['build', '', 'The whole map, written to storage/ (--stdout, --json)'],
            ['agents', '[--file=AGENTS.md]', 'The note that points coding agents to these commands'],
        ] as [$name, $args, $what]) {
            $this->stdout(sprintf('  %-52s', "php craft component-map/{$name} {$args}"), Console::FG_GREEN);
            $this->stdout("{$what}\n");
        }
        $this->stdout("\nOptions per command: php craft help component-map/<command>\n", Console::FG_GREY);
        return self::EXIT_OK;
    }
}
