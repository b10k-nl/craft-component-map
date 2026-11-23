<?php

namespace b10k\componentmap;

use b10k\componentmap\mcp\BlocksWithoutTemplateCheck;
use b10k\componentmap\mcp\BrokenReferencesCheck;
use b10k\componentmap\mcp\McpBrief;
use b10k\componentmap\mcp\McpCompat;
use b10k\componentmap\mcp\Tools;
use b10k\componentmap\models\Settings;
use b10k\componentmap\services\CraftContent;
use b10k\componentmap\services\CraftStructure;
use b10k\componentmap\services\MapService;
use b10k\componentmap\services\Reports;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use yii\base\Event;

/**
 * Component Map — how the templates of a Craft project fit together, for
 * developers and coding agents.
 *
 * Read-only: it reads templates and the content model's schema, and — to
 * list the pages to check — which entries contain which blocks. It writes
 * only to storage/ (and to AGENTS.md when asked, by `component-map/agents`).
 * Console commands, plus read-only tools on Craft's MCP server (Craft 6)
 * where allowAdminChanges is on — the same place it is meant for: local
 * development and staging.
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
                'reports' => Reports::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'b10k\\componentmap\\console\\controllers';
        }

        try {
            $this->registerMcp();
        } catch (\Throwable $e) {
            Craft::warning('Component Map could not register with Craft’s MCP server: ' . $e->getMessage(), 'component-map');
        }
    }

    /**
     * Whether Component Map offers anything on Craft's MCP server: the `mcp`
     * setting is on, and so is allowAdminChanges — local development and
     * staging, not production, where entries' titles and unpublished pages
     * have no business leaving through it.
     */
    public static function mcpEnabled(): bool
    {
        try {
            return self::getInstance()->getSettings()->mcp
                && Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Craft's MCP server (Craft 6): the `component_map_*` tools, two
     * checks in `content_model_audit`, and words in `info_get`. Nothing on an
     * older Craft — the classes are only named, never loaded — and nothing on
     * an ordinary request: all of it runs when an MCP server is built.
     *
     * Whatever goes wrong here is logged and left out: Component Map must
     * not be the reason a site's MCP server stops answering.
     */
    private function registerMcp(): void
    {
        // Craft 6: CraftCms\Cms\Mcp. Registration is deferred to the moment
        // Craft first reads its registries, so settings and request state are
        // known; a failure is logged and left out.
        if (!class_exists(\CraftCms\Cms\Mcp\CapabilityRegistry::class)) {
            return;
        }

        app(\CraftCms\Cms\Mcp\CapabilityRegistry::class)->defer(static function ($registry): void {
            try {
                if (self::mcpEnabled() && McpCompat::toolsFit()) {
                    $registry->register(Tools::class);
                }
            } catch (\Throwable $e) {
                McpCompat::warn('its tools', $e);
            }
        });

        if (class_exists(\CraftCms\Cms\Mcp\ContentModel\CheckRegistry::class)) {
            app(\CraftCms\Cms\Mcp\ContentModel\CheckRegistry::class)->defer(static function ($checks): void {
                try {
                    if (!self::mcpEnabled() || !McpCompat::checkFits()) {
                        return;
                    }
                    $ids = $checks->ids();
                    foreach ([BrokenReferencesCheck::class, BlocksWithoutTemplateCheck::class] as $check) {
                        if (!in_array($check::id(), $ids, true)) {
                            $checks->register($check);
                        }
                    }
                } catch (\Throwable $e) {
                    McpCompat::warn('its content-model checks', $e);
                }
            });
        }

        // Full instructions: info_get, users with control-panel access only.
        \Illuminate\Support\Facades\Event::listen(
            \CraftCms\Cms\Mcp\Events\CollectingAdminInstructions::class,
            static function (\CraftCms\Cms\Mcp\Events\CollectingAdminInstructions $event): void {
                if (!self::mcpEnabled()) {
                    return;
                }
                try {
                    // Through the adapter, as Tools does: Craft 6 answers it with its Gate.
                    if (!Craft::$app->getUser()->checkPermission('accessCp')) {
                        return;
                    }
                    $plugin = self::getInstance();
                    $plugin->getReports()->fresh();
                    $event->instructions[] = McpBrief::text(
                        $plugin->getMap()->explorer()->overview(null, 20),
                        McpCompat::toolsFit(),
                        $plugin->getSettings()->readEntries,
                    );
                } catch (\Throwable $e) {
                    Craft::warning('Component Map could not add its MCP instructions: ' . $e->getMessage(), 'component-map');
                }
            },
        );

        // Every client gets these, with or without control-panel access: one
        // harmless line, no paths, no entries.
        \Illuminate\Support\Facades\Event::listen(
            \CraftCms\Cms\Mcp\Events\CollectingServerInstructions::class,
            static function (\CraftCms\Cms\Mcp\Events\CollectingServerInstructions $event): void {
                if (!self::mcpEnabled() || !McpCompat::toolsFit()) {
                    return;
                }
                $event->instructions[] = McpBrief::SERVER_LINE;
            },
        );
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

    public function getReports(): Reports
    {
        /** @var Reports $service */
        $service = $this->get('reports');
        return $service;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
