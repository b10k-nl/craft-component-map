<?php

namespace b10k\componentmap\console\controllers;

use b10k\componentmap\helpers\Cli;
use craft\helpers\Console;

/**
 * Component Map — what the commands are.
 *
 *     craft:component-map:show <template|handle>  Where is it used, what does it use
 *     craft:component-map:impact <files> | --git | --since=<branch>  What a change affects
 *     craft:component-map:unused                What nothing uses
 *     craft:component-map:build                 Snapshot of the whole map in storage/
 *     craft:component-map:agents                The note for AGENTS.md / CLAUDE.md
 */
class DefaultController extends BaseController
{
    /**
     * Lists the Component Map commands.
     */
    public function actionIndex(): int
    {
        $this->stdout("Component Map\n\n", Console::BOLD);
        $commands = [
            ['show', '<template|handle>', 'Where a template, block or section is used, and what it uses'],
            ['impact', '<files> | --git | --since=main', 'What changing these files (or a branch) affects'],
            ['unused', '', 'Blocks no entry uses, templates nothing reaches'],
            ['build', '', 'The whole map, written to storage/ (--stdout, --json)'],
            ['agents', '[--file=AGENTS.md]', 'The note that points coding agents to these commands'],
        ];
        // One column wide enough for the longest command (see Component Check).
        $lines = array_map(static fn(array $c): string => Cli::craft("component-map:{$c[0]} {$c[1]}"), $commands);
        $width = max(array_map('mb_strlen', $lines)) + 2;
        foreach ($commands as $i => [$name, $args, $what]) {
            $this->stdout('  ' . $lines[$i] . str_repeat(' ', $width - mb_strlen($lines[$i])), Console::FG_GREEN);
            $this->stdout("{$what}\n");
        }
        $this->stdout("\nOptions per command: " . Cli::craft('component-map:<command> --help') . "\n", Console::FG_GREY);
        return self::EXIT_OK;
    }
}
