<?php

namespace b10k\componentmap\mcp;

use b10k\componentmap\Plugin;
use craft\mcp\contentmodel\Check;

/**
 * A block type a Matrix field allows, in a field whose other blocks each have
 * a template named after them — but this one has none, so the dispatcher
 * falls back, or renders nothing. As a check of Craft's
 * `content-model.audit` (Craft 5.12+).
 *
 * Only loaded when Craft has MCP: Plugin registers it behind a class_exists().
 */
class BlocksWithoutTemplateCheck implements Check
{
    public static function id(): string
    {
        return McpBrief::CHECK_BLOCKS_WITHOUT_TEMPLATE;
    }

    /** @return array{count: int, findings: list<mixed>} */
    public function run(int $limit): array
    {
        $plugin = Plugin::getInstance();
        $plugin->getReports()->fresh();
        $rows = array_map(static fn(array $r): array => [
            'handle' => $r['entryType'],
            'field' => $r['field'],
            'dispatchers' => $r['dispatchers'],
        ], $plugin->getMap()->explorer()->blocksWithoutTemplate());

        return ['count' => count($rows), 'findings' => array_slice($rows, 0, max(0, $limit))];
    }
}
