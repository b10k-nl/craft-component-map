<?php

namespace b10k\componentmap\console\controllers;

use craft\helpers\Console;

/**
 * Component Map — what the commands are.
 *
 *     component-map/build                 Write COMPONENT-MAP.md (and graph.json)
 *     component-map/show <template|handle>  Where is it used, what does it use
 *     component-map/impact <files> | --git  What a change affects
 */
class DefaultController extends BaseController
{
    public function actionIndex(): int
    {
        $this->stdout("Component Map\n\n", Console::BOLD);
        foreach ([
            ['build', '', 'Write COMPONENT-MAP.md for people and agents (--stdout, --json)'],
            ['show', '<template|handle>', 'Where a template, block or section is used, and what it uses'],
            ['impact', '<files> | --git', 'What changing these files affects'],
        ] as [$name, $args, $what]) {
            $this->stdout(sprintf('  %-46s', "php craft component-map/{$name} {$args}"), Console::FG_GREEN);
            $this->stdout("{$what}\n");
        }
        $this->stdout("\nOptions per command: php craft help component-map/<command>\n", Console::FG_GREY);
        return self::EXIT_OK;
    }
}
