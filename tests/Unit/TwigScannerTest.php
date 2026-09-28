<?php

namespace b10k\componentmap\tests\Unit;

use b10k\componentmap\services\TwigScanner;
use PHPUnit\Framework\TestCase;

class TwigScannerTest extends TestCase
{
    /**
     * @return array<int, array{tag: string, pattern: string, dynamic: bool, fallback: bool, conditional: bool}>
     */
    private function refs(string $code): array
    {
        $out = [];
        foreach ((new TwigScanner())->scan($code)['references'] as $ref) {
            foreach ($ref->targets as $t) {
                $out[] = ['tag' => $ref->tag] + array_intersect_key($t, array_flip(['pattern', 'dynamic', 'fallback', 'conditional']));
            }
        }
        return $out;
    }

    public function testStaticTags(): void
    {
        $refs = $this->refs(<<<'TWIG'
            {% extends '_layouts/site' %}
            {% include "_a.twig" with { x: 1 } only %}
            {%- embed '_b' -%}{% block c %}{% endblock %}{% endembed %}
            {% import '_macros' as m %}
            {% from '_forms' import input, select %}
            {% use '_blocks' %}
            TWIG);

        $this->assertSame(
            ['extends:_layouts/site', 'include:_a.twig', 'embed:_b', 'import:_macros', 'from:_forms', 'use:_blocks'],
            array_map(static fn($r) => "{$r['tag']}:{$r['pattern']}", $refs),
        );
        $this->assertFalse($refs[0]['dynamic']);
    }

    public function testFunctionsButNotMethods(): void
    {
        $refs = $this->refs(<<<'TWIG'
            {{ include('_c', { a: 1 }) }}
            {% set svg = source('_icons/x.svg') %}
            {{ craft.app.view.include('not-a-template-reference') }}
            TWIG);

        $this->assertSame(['include():_c', 'source():_icons/x.svg'], array_map(static fn($r) => "{$r['tag']}:{$r['pattern']}", $refs));
    }

    public function testDispatcherWithFallback(): void
    {
        $refs = $this->refs(<<<'TWIG'
            {% include [
                '_adapters/' ~ block.type.handle ~ '.twig',
                '_adapters/undefined.twig',
            ] with { block: block } only %}
            TWIG);

        $this->assertCount(2, $refs);
        $this->assertSame('_adapters/*.twig', $refs[0]['pattern']);
        $this->assertTrue($refs[0]['dynamic']);
        $this->assertFalse($refs[0]['fallback']);
        $this->assertSame('_adapters/undefined.twig', $refs[1]['pattern']);
        $this->assertTrue($refs[1]['fallback']);
    }

    public function testConditionalAndCoalesce(): void
    {
        $refs = $this->refs(<<<'TWIG'
            {% extends ajax ? '_bare' : '_site' %}
            {% include template ?? '_default' %}
            TWIG);

        $this->assertSame(['_bare', '_site', '*', '_default'], array_column($refs, 'pattern'));
        $this->assertTrue($refs[0]['conditional']);
        $this->assertTrue($refs[3]['conditional']);
    }

    public function testUnresolvableVariableIsKeptWithItsExpression(): void
    {
        $result = (new TwigScanner())->scan('{% include block.templatePath %}');
        $t = $result['references'][0]->targets[0];
        $this->assertSame('*', $t['pattern']);
        $this->assertTrue($t['dynamic']);
        $this->assertSame('block.templatePath', $t['expression']);
    }

    public function testCraftTagsAndUnknownFiltersDoNotMatter(): void
    {
        // {% cache %}, {% js %} and a plugin filter: the lexer does not care.
        $refs = $this->refs(<<<'TWIG'
            {% cache for 1 hour %}{% include '_x' %}{% endcache %}
            {% js %}console.log(1){% endjs %}
            {{ entry.body|someplugin_filter }}{% include '_y' %}
            TWIG);
        $this->assertSame(['_x', '_y'], array_column($refs, 'pattern'));
    }

    public function testCommentsAndStringsAreNotReferences(): void
    {
        $refs = $this->refs(<<<'TWIG'
            {# {% include '_commented' %} #}
            {{ "{% include '_in_a_string' %}" }}
            {% verbatim %}{% include '_verbatim' %}{% endverbatim %}
            TWIG);
        $this->assertSame([], $refs);
    }

    public function testSyntaxErrorFallsBackToStaticReferences(): void
    {
        $result = (new TwigScanner())->scan("{% include '_ok' %}\n{{ unclosed ");
        $this->assertNotNull($result['error']);
        $this->assertSame('_ok', $result['references'][0]->targets[0]['pattern']);
    }

    public function testLineNumbers(): void
    {
        $result = (new TwigScanner())->scan("a\nb\n{% include '_x' %}");
        $this->assertSame(3, $result['references'][0]->line);
    }
}
