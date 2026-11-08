<?php

namespace b10k\componentmap\models;

use craft\base\Model;

/**
 * Component Map settings — set them in `config/component-map.php`.
 */
class Settings extends Model
{
    /**
     * @var string Where `component-map/build` writes the Markdown map.
     * Local by default (storage/ is not committed): every developer runs the
     * plugin, and `show` / `impact` read the templates directly, so a
     * committed copy would only go stale. Empty = do not write Markdown.
     */
    public string $markdownFile = '@storage/component-map/COMPONENT-MAP.md';

    /**
     * @var string Where `component-map/build` writes the full graph as JSON.
     */
    public string $jsonFile = '@storage/component-map/graph.json';

    /**
     * @var bool Look up which entries contain the affected blocks, so `impact`
     * and `show` can list the pages to check. Read-only; canonical content
     * only. False = the map uses templates and the content model's schema only.
     */
    public bool $readEntries = true;

    /**
     * @var bool Offer the `component-map.*` tools, the content-model checks
     * and the `info.get` instructions on Craft's MCP server (Craft 5.12+).
     * Only where allowAdminChanges is on, whatever this says.
     */
    public bool $mcp = true;

    /**
     * @var string[] Glob patterns (relative to the templates folder) of
     * templates rendered from outside Twig — PHP, `config/routes.php`, a
     * plugin setting — so `unused` does not report them. E.g. `_emails/*`.
     */
    public array $entryPoints = [];

    /**
     * @var string[] Glob patterns (relative to the templates folder) left out
     * of the map, e.g. Component Guide story files.
     */
    public array $ignore = ['*.stories.twig'];

    public function rules(): array
    {
        return [
            [['markdownFile', 'jsonFile'], 'trim'],
            [['ignore', 'entryPoints'], 'each', 'rule' => ['string']],
        ];
    }
}
