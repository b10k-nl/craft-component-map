<?php

namespace b10k\componentmap\tests\Unit;

use b10k\componentmap\services\Explorer;
use b10k\componentmap\services\GraphBuilder;
use b10k\componentmap\services\TwigScanner;
use PHPUnit\Framework\TestCase;

/**
 * What Component Map adds to Craft's content-model audit and to an agent's
 * first look — and how careful it is about calling something broken.
 */
class AuditTest extends TestCase
{
    public function testIgnoreMissingIsRead(): void
    {
        $scanner = new TwigScanner();

        $this->assertTrue($scanner->scan("{% include '_x' ignore missing %}")['references'][0]->optional);
        $this->assertTrue($scanner->scan("{% include '_x' ignore missing with { a: 1 } only %}")['references'][0]->optional);
        $this->assertTrue($scanner->scan("{{ include('_x', {}, ignore_missing = true) }}")['references'][0]->optional);
        $this->assertFalse($scanner->scan("{% include '_x' %}")['references'][0]->optional);
        $this->assertFalse($scanner->scan("{{ include('_x', { ignore_missing: true }) }}")['references'][0]->optional, 'a context key, not the argument');
    }

    public function testBrokenReferencesAreOnlyTheCertainOnes(): void
    {
        $templates = Fixtures::templates() + [
            '_partials/a.twig' => <<<'TWIG'
                {% include ['_partials/custom', '_partials/default'] %}
                {% include '_partials/gone' ignore missing %}
                {% include ['_partials/one', '_partials/two'] %}
                {% extends ajax ? '_layouts/bare' : '_layouts/nope' %}
                TWIG,
            '_partials/default.twig' => 'default',
        ];
        $broken = (new Explorer((new GraphBuilder())->build($templates, Fixtures::structure())))->brokenReferences();

        $this->assertSame([
            ['template' => '_components/orphan.twig', 'line' => 1, 'tag' => 'include', 'reference' => '_components/missing'],
            ['template' => '_partials/a.twig', 'line' => 3, 'tag' => 'include', 'reference' => '_partials/one'],
            ['template' => '_partials/a.twig', 'line' => 3, 'tag' => 'include', 'reference' => '_partials/two'],
            ['template' => '_partials/a.twig', 'line' => 4, 'tag' => 'extends', 'reference' => '_layouts/nope'],
        ], $broken, 'a fallback list with an entry that exists, and ignore missing, are fine; a conditional branch is not');
    }

    public function testBlocksADispatcherCannotRender(): void
    {
        $e = new Explorer((new GraphBuilder())->build(Fixtures::templates(), Fixtures::structure()));

        $this->assertSame([
            ['entryType' => 'quote', 'field' => 'contentBlocks', 'dispatchers' => ['_blocks.twig']],
        ], $e->blocksWithoutTemplate(), 'card is rendered by its cards grid, so gridCards is not dispatched');
    }

    public function testOverview(): void
    {
        $o = (new Explorer((new GraphBuilder())->build(Fixtures::templates(), Fixtures::structure())))->overview(['hero' => 3]);

        $this->assertSame(['templates' => 15, 'sections' => 2, 'matrixFields' => 2, 'entryTypes' => 5], $o['counts']);
        $this->assertSame([['template' => '_blocks.twig', 'patterns' => ['_adapters/*.twig'], 'renders' => ['cardsGrid', 'hero']]], $o['dispatchers']);
        $this->assertSame(['kind' => 'section', 'handle' => 'home', 'name' => 'Home', 'templates' => ['index.twig']], $o['pages'][0]);

        $blocks = array_column($o['blocks'], null, 'entryType');
        $this->assertSame([['template' => '_adapters/hero.twig', 'via' => '_blocks.twig']], $blocks['hero']['renderedBy']);
        $this->assertSame(3, $blocks['hero']['entries']);
        $this->assertSame(0, $blocks['quote']['entries']);
        $this->assertArrayNotHasKey('page', $blocks, 'a page type is not a block');
        $this->assertSame(2, $o['notResolved']['total']);
        $this->assertFalse($o['truncated']);
    }
}
