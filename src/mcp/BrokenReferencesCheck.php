<?php

namespace b10k\componentmap\mcp;

use b10k\componentmap\Plugin;
use craft\mcp\contentmodel\Check;

/**
 * A template that includes, embeds or extends one that does not exist — as a
 * check of Craft's `content-model.audit` (Craft 5.12+). Only certain cases:
 * `ignore missing`, and a fallback list with an entry that exists, are fine.
 *
 * Only loaded when Craft has MCP: Plugin registers it behind a class_exists().
 */
class BrokenReferencesCheck implements Check
{
    public static function id(): string
    {
        return McpBrief::CHECK_BROKEN_REFERENCES;
    }

    /** @return array{count: int, findings: list<mixed>} */
    public function run(int $limit): array
    {
        $plugin = Plugin::getInstance();
        $plugin->getReports()->fresh();
        $rows = $plugin->getMap()->explorer()->brokenReferences();

        return ['count' => count($rows), 'findings' => array_slice($rows, 0, max(0, $limit))];
    }
}
