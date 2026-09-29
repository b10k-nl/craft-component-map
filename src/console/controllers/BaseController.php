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

    /**
     * @var int How many entries to list (`impact`, `show`). 0 = all. With --json, all.
     */
    public int $limit = 15;

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

    /**
     * Entries to check, most reasons first, with title, URL and status.
     * Null when reading entries is off or fails (with a warning).
     *
     * @param array<int, string[]> $reasons element id => reasons
     * @return array{total: int, entries: array<int, array<string, mixed>>}|null
     */
    protected function describeEntries(array $reasons, int $limit): ?array
    {
        uksort($reasons, static fn(int $a, int $b) => [count($reasons[$b]), $a] <=> [count($reasons[$a]), $b]);
        $ids = $limit > 0 ? array_slice(array_keys($reasons), 0, $limit) : array_keys($reasons);
        try {
            $entries = $this->plugin()->getCraftContent()->describe($ids);
        } catch (\Throwable $e) {
            $this->warnEntries($e);
            return null;
        }
        foreach ($entries as &$entry) {
            $entry['reasons'] = $reasons[$entry['id']] ?? [];
        }
        return ['total' => count($reasons), 'entries' => $entries];
    }

    protected function content(): ?\b10k\componentmap\services\ContentIndex
    {
        if (!$this->plugin()->getSettings()->readEntries) {
            return null;
        }
        try {
            return $this->plugin()->getCraftContent()->index();
        } catch (\Throwable $e) {
            $this->warnEntries($e);
            return null;
        }
    }

    private function warnEntries(\Throwable $e): void
    {
        if (!$this->json) {
            $this->stderr('Could not read entries: ' . $e->getMessage() . "\n", Console::FG_YELLOW);
        }
    }

    /**
     * @param array{total: int, entries: array<int, array<string, mixed>>} $found
     */
    protected function printEntries(string $title, array $found): void
    {
        $this->stdout("{$title} ({$found['total']})\n", Console::FG_YELLOW);
        if ($found['total'] === 0) {
            $this->stdout("  —\n\n");
            return;
        }
        $width = min(40, max(array_map(static fn($e) => mb_strlen($e['title']), $found['entries']) ?: [0]));
        foreach ($found['entries'] as $e) {
            $title = mb_strimwidth($e['title'], 0, 40, '…');
            $this->stdout('  ' . $title . str_repeat(' ', max(0, $width - mb_strlen($title))) . '  ');
            $this->stdout($e['url'] ?? ('(no URL) ' . ($e['cpUrl'] ?? '')), $e['url'] ? Console::FG_CYAN : Console::FG_GREY);
            $notes = array_filter([
                $e['status'] !== 'live' ? $e['status'] : null,
                isset($e['reasons']) && $e['reasons'] !== [] ? implode(', ', $e['reasons']) : null,
            ]);
            if ($notes !== []) {
                $this->stdout('   ' . implode(' · ', $notes), Console::FG_GREY);
            }
            $this->stdout("\n");
        }
        $more = $found['total'] - count($found['entries']);
        if ($more > 0) {
            $this->stdout("  … and {$more} more (--limit=0 for all, or --json)\n", Console::FG_GREY);
        }
        $this->stdout("\n");
    }

    protected static function short(string $id): string
    {
        $pos = strpos($id, ':');
        return $pos === false ? $id : substr($id, $pos + 1);
    }
}
