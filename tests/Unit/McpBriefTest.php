<?php

namespace b10k\componentmap\tests\Unit;

use b10k\componentmap\mcp\McpBrief;
use b10k\componentmap\services\Explorer;
use b10k\componentmap\services\GraphBuilder;
use PHPUnit\Framework\TestCase;

class McpBriefTest extends TestCase
{
    private function overview(): array
    {
        return (new Explorer((new GraphBuilder())->build(Fixtures::templates(), Fixtures::structure())))->overview(null, 20);
    }

    public function testTellsTheAgentThisProjectsConvention(): void
    {
        $text = McpBrief::text($this->overview(), true, true);

        $this->assertStringContainsString('This site: 15 templates, 2 sections, 2 Matrix fields.', $text);
        $this->assertStringContainsString('rendered by `_blocks.twig` through `_adapters/*.twig`', $text);
        $this->assertStringContainsString(': cardsGrid, hero.', $text);
        $this->assertStringContainsString('`component-map.impact`', $text);
        $this->assertStringContainsString('entries to open in a browser', $text);
        $this->assertStringContainsString('2 references it cannot follow', $text);
    }

    public function testSaysLessWhenToolsOrEntriesAreMissing(): void
    {
        $text = McpBrief::text($this->overview(), false, false);

        $this->assertStringNotContainsString('component-map.impact', $text);
        $this->assertStringContainsString('Not in the map:', $text);

        $none = McpBrief::text(['counts' => ['templates' => 1, 'sections' => 1, 'matrixFields' => 0], 'dispatchers' => [], 'notResolved' => ['total' => 0]], true, false);
        $this->assertStringContainsString('No dispatcher was found', $none);
        $this->assertStringContainsString('1 template, 1 section, 0 Matrix fields', $none);
        $this->assertStringNotContainsString('entries to open', $none);
    }

    public function testServerLineIsShortAndNamesNoPaths(): void
    {
        $this->assertLessThan(250, strlen(McpBrief::SERVER_LINE));
        $this->assertStringNotContainsString('/', str_replace('`component-map.*`', '', McpBrief::SERVER_LINE));
    }
}
