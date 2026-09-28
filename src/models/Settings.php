<?php

namespace b10k\componentmap\models;

use craft\base\Model;

/**
 * Component Map settings — set them in `config/component-map.php`.
 */
class Settings extends Model
{
    /**
     * @var string Where `component-map/build` writes the Markdown map. Commit
     * it: it is the file a coding agent reads before touching templates.
     * Empty = do not write Markdown.
     */
    public string $markdownFile = '@root/COMPONENT-MAP.md';

    /**
     * @var string Where `component-map/build` writes the full graph as JSON.
     */
    public string $jsonFile = '@storage/component-map/graph.json';

    /**
     * @var string[] Glob patterns (relative to the templates folder) left out
     * of the map, e.g. Component Guide story files.
     */
    public array $ignore = ['*.stories.twig'];

    public function rules(): array
    {
        return [
            [['markdownFile', 'jsonFile'], 'trim'],
            ['ignore', 'each', 'rule' => ['string']],
        ];
    }
}
