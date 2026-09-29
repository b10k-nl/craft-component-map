<?php

namespace b10k\componentmap\console\controllers;

use b10k\componentmap\services\AgentsNote;
use Craft;
use craft\helpers\Console;
use craft\helpers\FileHelper;

/**
 * The note that tells a coding agent to use Component Map — for `AGENTS.md`
 * or `CLAUDE.md` in the project root. This is the one thing worth committing:
 * it does not change when the templates do.
 *
 *     php craft component-map/agents                    # print it
 *     php craft component-map/agents --file=AGENTS.md   # add it to the file (or update it)
 *     php craft component-map/agents --file=CLAUDE.md
 */
class AgentsController extends BaseController
{
    /**
     * @var string File in the project root to add the note to. Empty = print it.
     */
    public string $file = '';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['file']);
    }

    public function actionIndex(): int
    {
        $craft = $this->plugin()->getMap()->craftCommand();

        if ($this->file === '') {
            if ($this->json) {
                $this->writeJson(['status' => 'ok', 'note' => AgentsNote::text($craft)]);
            } else {
                $this->stdout(AgentsNote::text($craft) . "\n");
            }
            return self::EXIT_OK;
        }

        $file = ltrim(str_replace('\\', '/', $this->file), '/');
        if ($file === '' || str_contains($file, '..')) {
            return $this->error('--file must be a path inside the project, e.g. AGENTS.md or CLAUDE.md.');
        }

        $path = rtrim((string)Craft::getAlias('@root'), '/\\') . '/' . $file;
        $before = is_file($path) ? (string)file_get_contents($path) : '';
        $after = AgentsNote::addTo($before, $craft);

        if ($after === $before) {
            $status = 'unchanged';
        } else {
            FileHelper::writeToFile($path, $after);
            $status = str_contains($before, AgentsNote::START) ? 'updated' : 'added';
        }

        if ($this->json) {
            $this->writeJson(['status' => 'ok', 'file' => $path, 'result' => $status]);
        } else {
            $this->stdout(ucfirst($status) . ": the Component Map note in {$path}\n", Console::FG_GREEN);
        }
        return self::EXIT_OK;
    }
}
