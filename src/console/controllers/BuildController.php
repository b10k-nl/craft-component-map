<?php

namespace b10k\componentmap\console\controllers;

use b10k\componentmap\services\MarkdownWriter;
use Craft;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use craft\helpers\Json;

/**
 * Builds the map and writes it: Markdown for people and agents
 * (COMPONENT-MAP.md), JSON for tools.
 *
 *     php craft component-map/build
 *     php craft component-map/build --json      # the whole graph to stdout, writes nothing
 *     php craft component-map/build --stdout    # the Markdown to stdout, writes nothing
 */
class BuildController extends BaseController
{
    /**
     * @var bool Print the Markdown instead of writing files.
     */
    public bool $stdout = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['stdout']);
    }

    public function actionIndex(): int
    {
        $plugin = $this->plugin();
        $settings = $plugin->getSettings();
        $graph = $plugin->getMap()->graph();

        if ($this->json) {
            $this->writeJson($graph->toArray());
            return self::EXIT_OK;
        }

        $markdown = (new MarkdownWriter())->write($graph, (string)Craft::$app->getSystemName());

        if ($this->stdout) {
            $this->stdout($markdown . "\n");
            return self::EXIT_OK;
        }

        $written = [];
        if ($settings->markdownFile !== '') {
            $path = (string)Craft::getAlias($settings->markdownFile);
            FileHelper::writeToFile($path, $markdown . "\n");
            $written[] = $path;
        }
        if ($settings->jsonFile !== '') {
            $path = (string)Craft::getAlias($settings->jsonFile);
            FileHelper::writeToFile($path, Json::encode($graph->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $written[] = $path;
        }

        $this->stdout(sprintf(
            "%d templates, %d sections, %d Matrix fields, %d entry types.\n",
            count($graph->nodes('template')),
            count($graph->nodes('section')),
            count($graph->nodes('field')),
            count($graph->nodes('entryType')),
        ), Console::FG_GREY);
        foreach ($written as $path) {
            $this->stdout("Wrote {$path}\n", Console::FG_GREEN);
        }

        $unresolved = count($graph->unresolved());
        if ($unresolved > 0) {
            $this->stdout("{$unresolved} reference(s) could not be resolved — listed under “Not resolved”.\n", Console::FG_YELLOW);
        }

        return self::EXIT_OK;
    }
}
