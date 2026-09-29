<?php

namespace b10k\componentmap;

use b10k\componentmap\models\Settings;
use b10k\componentmap\services\CraftContent;
use b10k\componentmap\services\CraftStructure;
use b10k\componentmap\services\MapService;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;

/**
 * Component Map — how the templates of a Craft project fit together, for
 * developers and coding agents.
 *
 * Console only and read-only: it reads templates and the content model's
 * schema, and — to list the pages to check — which entries contain which
 * blocks. It writes only to storage/ (and to AGENTS.md when
 * asked, by `component-map/agents`), and exposes nothing over HTTP.
 * Meant for local development.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '0.1.0';
    public bool $hasCpSettings = false;
    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'map' => MapService::class,
                'craftStructure' => CraftStructure::class,
                'craftContent' => CraftContent::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'b10k\\componentmap\\console\\controllers';
        }
    }

    public function getMap(): MapService
    {
        /** @var MapService $service */
        $service = $this->get('map');
        return $service;
    }

    public function getCraftStructure(): CraftStructure
    {
        /** @var CraftStructure $service */
        $service = $this->get('craftStructure');
        return $service;
    }

    public function getCraftContent(): CraftContent
    {
        /** @var CraftContent $service */
        $service = $this->get('craftContent');
        return $service;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
