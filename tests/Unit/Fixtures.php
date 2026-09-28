<?php

namespace b10k\componentmap\tests\Unit;

/**
 * A small site built like most Craft page builders: page template → layout,
 * page template → dispatcher → one adapter per block type (chosen by
 * `block.type.handle`) → presentational components.
 */
final class Fixtures
{
    /**
     * @return array<string, string>
     */
    public static function templates(): array
    {
        return [
            'index.twig' => <<<'TWIG'
                {% extends '_layouts/site.twig' %}
                {% block content %}
                    {% include '_blocks.twig' with { blocks: entry.contentBlocks ?? null } only %}
                {% endblock %}
                TWIG,
            'news/_entry.twig' => <<<'TWIG'
                {% extends craft.app.request.isAjax ? '_layouts/bare' : '_layouts/site' %}
                {% import '_macros/ui' as ui %}
                {% block content %}{{ ui.title(entry.title) }}{% include '_blocks' %}{% endblock %}
                TWIG,
            '_layouts/site.twig' => '<html>{% include "_partials/header" %}{% block content %}{% endblock %}</html>',
            '_layouts/bare.twig' => '{% block content %}{% endblock %}',
            '_partials/header.twig' => '<header>{{ source("_icons/logo.svg", ignore_missing = true) }}</header>',
            '_macros/ui.twig' => '{% macro title(t) %}<h1>{{ t }}</h1>{% endmacro %}',
            '_blocks.twig' => <<<'TWIG'
                {# Dispatcher #}
                {% for block in blocks.all() %}
                    {%- include [
                        '_adapters/' ~ block.type.handle ~ '.twig',
                        '_adapters/undefined.twig',
                    ] with { block: block } only -%}
                {% endfor %}
                TWIG,
            '_adapters/hero.twig' => "{% include '_components/hero.twig' with { heading: block.heading } only %}",
            '_adapters/cardsGrid.twig' => <<<'TWIG'
                {% include '_components/cards-grid' with {
                    items: block.gridCards.all()|map(c => { title: c.title }),
                } only %}
                TWIG,
            '_adapters/undefined.twig' => '{% if devMode %}Unknown block {{ block.type.handle }}{% endif %}',
            '_components/hero.twig' => '<section>{{ include("_components/button", { label: "Go" }) }}</section>',
            '_components/cards-grid.twig' => "{% for i in items %}{% include '_components/card' with i only %}{% endfor %}",
            '_components/card.twig' => '<article>{% include "_components/button" %}</article>',
            '_components/button.twig' => '<a class="btn">{{ label ?? "" }}</a>',
            '_components/orphan.twig' => '{% include "_components/missing" %}{% include someVariable %}',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function structure(): array
    {
        return [
            'sections' => [
                ['handle' => 'home', 'name' => 'Home', 'type' => 'single', 'templates' => ['index.twig'], 'entryTypes' => ['page']],
                ['handle' => 'news', 'name' => 'News', 'type' => 'channel', 'templates' => ['news/_entry'], 'entryTypes' => ['page']],
            ],
            'entryTypes' => [
                ['handle' => 'page', 'name' => 'Page', 'fields' => ['contentBlocks']],
                ['handle' => 'hero', 'name' => 'Hero', 'fields' => []],
                ['handle' => 'cardsGrid', 'name' => 'Cards Grid', 'fields' => ['gridCards']],
                ['handle' => 'card', 'name' => 'Card', 'fields' => []],
                ['handle' => 'quote', 'name' => 'Quote', 'fields' => []],
            ],
            'fields' => [
                ['handle' => 'contentBlocks', 'name' => 'Content Blocks', 'entryTypes' => ['hero', 'cardsGrid', 'quote']],
                ['handle' => 'gridCards', 'name' => 'Grid Cards', 'entryTypes' => ['card']],
            ],
        ];
    }
}
