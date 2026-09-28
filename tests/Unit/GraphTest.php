<?php

namespace b10k\componentmap\tests\Unit;

use b10k\componentmap\models\Graph;
use b10k\componentmap\services\Explorer;
use b10k\componentmap\services\GraphBuilder;
use b10k\componentmap\services\MarkdownWriter;
use PHPUnit\Framework\TestCase;

class GraphTest extends TestCase
{
    private function graph(): Graph
    {
        return (new GraphBuilder())->build(Fixtures::templates(), Fixtures::structure());
    }

    public function testTemplateEdges(): void
    {
        $g = $this->graph();
        $edges = array_map(
            static fn(array $e) => substr($e['to'], 9) . ($e['dynamic'] ?? false ? ' (dynamic)' : '') . ($e['fallback'] ?? false ? ' (fallback)' : ''),
            $g->outgoing('template:_blocks.twig'),
        );
        $this->assertSame([
            '_adapters/cardsGrid.twig (dynamic)',
            '_adapters/hero.twig (dynamic)',
            '_adapters/undefined.twig (dynamic) (fallback)',
        ], $edges);
    }

    public function testDispatcherBecomesRendersEdges(): void
    {
        $g = $this->graph();
        $renders = $g->outgoing('entryType:hero', ['renders']);
        $this->assertCount(1, $renders);
        $this->assertSame('template:_adapters/hero.twig', $renders[0]['to']);
        $this->assertSame('_blocks.twig', $renders[0]['via']);
        // "undefined" is not an entry type: no renders edge.
        $this->assertSame([], $g->incoming('template:_adapters/undefined.twig', ['renders']));
    }

    public function testConditionalExtendsAndPageTemplates(): void
    {
        $g = $this->graph();
        $targets = array_column($g->outgoing('template:news/_entry.twig', ['extends']), 'to');
        $this->assertSame(['template:_layouts/bare.twig', 'template:_layouts/site.twig'], $targets);
        $this->assertSame('template:news/_entry.twig', $g->outgoing('section:news', ['page'])[0]['to']);
    }

    public function testUnresolvedAreRecordedNotDropped(): void
    {
        $unresolved = $this->graph()->unresolved();
        $reasons = array_map(static fn(array $u) => "{$u['from']}:{$u['pattern']}:{$u['reason']}", $unresolved);
        $this->assertContains('_components/orphan.twig:_components/missing:template not found', $reasons);
        $this->assertContains('_components/orphan.twig:*:expression cannot be resolved statically', $reasons);
        // source("_icons/logo.svg") — no such file in the fixture.
        $this->assertContains('_partials/header.twig:_icons/logo.svg:template not found', $reasons);
    }

    public function testWhereIsItUsed(): void
    {
        $e = new Explorer($this->graph());
        $d = $e->describe($e->find('_components/button'));

        $this->assertSame(['_components/card.twig', '_components/hero.twig'], array_column($d['usedBy'], 'template'));
        $this->assertSame(['cardsGrid', 'hero'], $d['affects']['entryTypes']);
        $this->assertSame(['section:home', 'section:news'], $d['affects']['pages']);
    }

    public function testImpactOfAComponentChange(): void
    {
        $impact = (new Explorer($this->graph()))->impact(['_components/card.twig', 'web/dist/app.css']);

        $this->assertSame(['cardsGrid'], $impact['entryTypes'], 'only the block that renders cards');
        $this->assertSame(['web/dist/app.css'], $impact['unmapped']);
        $this->assertContains('_adapters/cardsGrid.twig', $impact['templates']);
        $this->assertContains('index.twig', $impact['templates']);
        $this->assertNotContains('_adapters/hero.twig', $impact['templates']);
    }

    public function testImpactOfTheDispatcherOrLayoutIsEveryBlock(): void
    {
        $e = new Explorer($this->graph());
        $this->assertSame(['cardsGrid', 'hero'], $e->impact(['_blocks.twig'])['entryTypes']);
        $this->assertSame(['cardsGrid', 'hero'], $e->impact(['_layouts/site.twig'])['entryTypes'], 'the layout wraps every block');
        $this->assertSame([], $e->impact(['_adapters/undefined.twig'])['entryTypes']);
    }

    public function testEntryTypeView(): void
    {
        $e = new Explorer($this->graph());
        $d = $e->describe($e->find('cardsGrid'));

        $this->assertSame(['contentBlocks'], $d['allowedIn']);
        $this->assertSame('_adapters/cardsGrid.twig', $d['renderedBy'][0]['template']);
        $this->assertSame(['_components/button.twig', '_components/card.twig', '_components/cards-grid.twig'], $d['renderedBy'][0]['uses']);
        $this->assertSame(['section:home', 'section:news'], $d['pagesUsingIt']);

        $quote = $e->describe('entryType:quote');
        $this->assertSame([], $quote['renderedBy'], 'allowed, but no adapter exists');
    }

    public function testFindAcceptsWhatPeopleType(): void
    {
        $e = new Explorer($this->graph());
        $this->assertSame('template:_components/card.twig', $e->find('templates/_components/card.twig'));
        $this->assertSame('template:_components/card.twig', $e->find('_components/card'));
        $this->assertSame('section:news', $e->find('news'));
        $this->assertSame('field:gridCards', $e->find('gridCards'));
        $this->assertNull($e->find('nope'));
    }

    public function testMarkdownIsDeterministicAndReadable(): void
    {
        $writer = new MarkdownWriter();
        $md = $writer->write($this->graph(), 'Test site');

        $this->assertSame($md, $writer->write($this->graph(), 'Test site'));
        $this->assertStringContainsString('# Component map — Test site', $md);
        $this->assertStringContainsString('- **Home** (`home`, single) → `index.twig`', $md);
        $this->assertStringContainsString('| Hero (`hero`) | `_adapters/hero.twig` | `_blocks.twig` | `_components/button.twig`, `_components/hero.twig` |', $md);
        $this->assertStringContainsString('| Quote (`quote`) | _no template named after it_ | | |', $md);
        $this->assertStringContainsString("### `_components/button.twig`\n\n- used by: `_components/card.twig`, `_components/hero.twig` (include())", $md);
        $this->assertStringContainsString('- a change affects: blocks `cardsGrid`, `hero`; pages `home`, `news`', $md);
        $this->assertStringContainsString('## Not resolved', $md);
    }
}
