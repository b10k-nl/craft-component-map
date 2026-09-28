<?php

namespace b10k\componentmap\console\controllers;

use b10k\componentmap\Plugin;
use craft\helpers\Console;
use craft\helpers\Json;
use yii\console\Controller;

/**
 * Exit codes: 0 ok, 1 not found / nothing to report, 2 could not run.
 */
abstract class BaseController extends Controller
{
    public const EXIT_OK = 0;
    public const EXIT_NOT_FOUND = 1;
    public const EXIT_ERROR = 2;

    /**
     * @var bool Print machine-readable JSON to stdout.
     */
    public bool $json = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['json']);
    }

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function writeJson(array $data): void
    {
        $this->stdout(Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function error(string $message, int $code = self::EXIT_ERROR, array $extra = []): int
    {
        if ($this->json) {
            $this->writeJson(['status' => 'error', 'error' => $message] + $extra);
        } else {
            $this->stderr($message . "\n", Console::FG_RED);
        }
        return $code;
    }

    protected static function short(string $id): string
    {
        $pos = strpos($id, ':');
        return $pos === false ? $id : substr($id, $pos + 1);
    }
}
