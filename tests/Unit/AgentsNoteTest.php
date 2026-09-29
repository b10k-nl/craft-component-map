<?php

namespace b10k\componentmap\tests\Unit;

use b10k\componentmap\services\AgentsNote;
use PHPUnit\Framework\TestCase;

class AgentsNoteTest extends TestCase
{
    public function testUsesTheProjectsCraftCommand(): void
    {
        $note = AgentsNote::text('ddev craft');

        $this->assertStringStartsWith(AgentsNote::START, $note);
        $this->assertStringEndsWith(AgentsNote::END, $note);
        $this->assertStringContainsString('ddev craft component-map/impact --git --json', $note);
        $this->assertStringNotContainsString('php craft', $note);
    }

    public function testAddsToAnEmptyOrExistingFile(): void
    {
        $this->assertSame(AgentsNote::text() . "\n", AgentsNote::addTo(''));

        $out = AgentsNote::addTo("# Project\n\nRun tests first.\n");
        $this->assertStringStartsWith("# Project\n\nRun tests first.\n\n" . AgentsNote::START, $out);
    }

    public function testAddingAgainReplacesInsteadOfDuplicating(): void
    {
        $once = AgentsNote::addTo("# Project\n", 'php craft');
        $this->assertSame($once, AgentsNote::addTo($once, 'php craft'));

        $switched = AgentsNote::addTo($once . "\nMore notes.\n", 'ddev craft');
        $this->assertSame(1, substr_count($switched, AgentsNote::START));
        $this->assertStringContainsString('ddev craft component-map/show', $switched);
        $this->assertStringNotContainsString('php craft component-map/show', $switched);
        $this->assertStringEndsWith("\nMore notes.\n", $switched);
    }
}
