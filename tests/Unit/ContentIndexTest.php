<?php

namespace b10k\componentmap\tests\Unit;

use b10k\componentmap\services\ContentIndex;
use PHPUnit\Framework\TestCase;

class ContentIndexTest extends TestCase
{
    /**
     * Home (1) and About (2) are pages; a news post (3) too. Blocks:
     * a Hero on Home and About, a Cards Grid on About holding two Cards,
     * a Card nested in a Cards Grid on a category (50), and a Quote whose
     * owner is not a page (a global set, 60).
     */
    private function index(): ContentIndex
    {
        return new ContentIndex(
            nested: [
                10 => ['type' => 'hero', 'owners' => [1]],
                11 => ['type' => 'hero', 'owners' => [2]],
                20 => ['type' => 'cardsGrid', 'owners' => [2]],
                21 => ['type' => 'card', 'owners' => [20]],
                22 => ['type' => 'card', 'owners' => [20]],
                30 => ['type' => 'cardsGrid', 'owners' => [50]],
                31 => ['type' => 'card', 'owners' => [30]],
                40 => ['type' => 'quote', 'owners' => [60]],
            ],
            pages: [
                1 => ['section' => 'home', 'type' => 'contentBlocks'],
                2 => ['section' => 'pages', 'type' => 'page'],
                3 => ['section' => 'news', 'type' => 'post'],
            ],
        );
    }

    public function testBlocksAreFoundOnThePagesThatShowThemAtAnyDepth(): void
    {
        $i = $this->index();

        $this->assertSame([1, 2], $i->containing('hero'));
        $this->assertSame([2, 50], $i->containing('card'), 'through the Cards Grid it sits in');
        $this->assertSame([60], $i->containing('quote'), 'owners that are not entries count as pages');
        $this->assertSame([], $i->containing('faq'));
    }

    public function testSectionsTypesAndUsage(): void
    {
        $i = $this->index();

        $this->assertSame([3], $i->inSection('news'));
        $this->assertSame([2], $i->ofType('page'));
        $this->assertSame(['card' => 2, 'cardsGrid' => 2, 'hero' => 2, 'quote' => 1], $i->usage());
    }

    public function testEntriesToCheckCarryTheirReasons(): void
    {
        $check = $this->index()->toCheck(['hero', 'card'], ['news']);

        $this->assertSame([
            1 => ['hero'],
            2 => ['hero', 'card'],
            3 => ['page'],
            50 => ['card'],
        ], $check);
    }

    public function testAnOwnershipCycleDoesNotLoop(): void
    {
        $i = new ContentIndex([5 => ['type' => 'a', 'owners' => [6]], 6 => ['type' => 'b', 'owners' => [5]]], []);

        $this->assertSame([], $i->containing('a'));
    }
}
