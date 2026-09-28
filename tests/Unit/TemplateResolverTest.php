<?php

namespace b10k\componentmap\tests\Unit;

use b10k\componentmap\services\TemplateResolver;
use PHPUnit\Framework\TestCase;

class TemplateResolverTest extends TestCase
{
    private function resolver(): TemplateResolver
    {
        return new TemplateResolver([
            'index.twig',
            '_blocks/hero.twig',
            '_blocks/cta.html',
            '_blocks/cards/index.twig',
            '_adapters/hero.twig',
            '_adapters/cardsGrid.twig',
            '_adapters/deep/nested.twig',
            '_icons/logo.svg',
        ]);
    }

    public function testCraftResolutionOrder(): void
    {
        $r = $this->resolver();
        $this->assertSame(['_blocks/hero.twig'], $r->resolve('_blocks/hero'));
        $this->assertSame(['_blocks/hero.twig'], $r->resolve('_blocks/hero.twig'));
        $this->assertSame(['_blocks/cta.html'], $r->resolve('_blocks/cta'));
        $this->assertSame(['_blocks/cards/index.twig'], $r->resolve('_blocks/cards'));
        $this->assertSame(['index.twig'], $r->resolve('/index'));
        $this->assertSame(['_icons/logo.svg'], $r->resolve('_icons/logo.svg'));
        $this->assertSame([], $r->resolve('_blocks/missing'));
    }

    public function testPatternsMatchOneSegmentFirst(): void
    {
        $r = $this->resolver();
        $this->assertSame(['_adapters/cardsGrid.twig', '_adapters/hero.twig'], $r->resolve('_adapters/*.twig'));
        $this->assertSame(['_adapters/cardsGrid.twig', '_adapters/hero.twig'], $r->resolve('_adapters/*'));
        // Only when one segment finds nothing does * span folders.
        $this->assertSame(['_adapters/deep/nested.twig'], $r->resolve('_adapters/*/nested'));
    }

    public function testNothingOutsideTheSite(): void
    {
        $r = $this->resolver();
        $this->assertSame([], $r->resolve('_self'));
        $this->assertSame([], $r->resolve('@formie/templates/form'));
        $this->assertSame([], $r->resolve('*'), 'a bare wildcard would match the whole site');
        $this->assertTrue($r->isExternal('@formie/x'));
    }
}
