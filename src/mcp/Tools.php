<?php

namespace b10k\componentmap\mcp;

use b10k\componentmap\Plugin;
use b10k\componentmap\services\ReportException;
use Craft;
use craft\mcp\attributes\RequiresPermission;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Component Map's tools on Craft's MCP server (Craft 5.12+): how the site's
 * Twig templates fit together and with the content model, and what a change
 * affects — what an agent cannot learn from Craft's own tools, which do not
 * see templates.
 *
 * All read only. The map is built afresh on every call: over stdio the
 * server is one long process, and templates change between calls.
 *
 * Shown only where allowAdminChanges is on (Plugin registers nothing
 * elsewhere), to users with control-panel access; each call checks again.
 * Entries the user may not view are counted, not listed.
 */
class Tools
{
    private const FILES_SCHEMA = [
        'type' => 'array',
        'items' => ['type' => 'string'],
        'description' => 'Files you changed: templates (`_blocks/hero.twig`, `templates/_blocks/hero.twig`), project config files (`config/project/entryTypes/hero--….yaml`), anything else. Deleted files count too.',
    ];

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'component-map.overview',
        description: 'The site\'s templates at a glance: each section\'s page template, the dispatchers that render page-builder blocks, the template each block type is rendered by (and how many entries use it), and the references the map cannot follow. A good first call before working on templates.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function overview(
        #[Schema(description: 'Most pages and blocks to list. Default 50.', minimum: 1, maximum: 500)]
        int $limit = 50,
    ): array {
        return $this->run(static fn(Plugin $plugin): array => $plugin->getReports()->overview($limit));
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'component-map.show',
        description: 'Everything the map knows about one template, block (entry type), section or Matrix field: which templates use it and which it uses (with line numbers), the template a block is rendered by and through which dispatcher, the blocks and pages a change to it affects, and the entries a block is on, with URLs.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function show(
        #[Schema(description: 'A template path (`_blocks/hero`, `_blocks/hero.twig`, `templates/_blocks/hero.twig`), or the handle of an entry type, section or Matrix field.')]
        string $query,
        #[Schema(description: 'Most entries to list. Default 15.', minimum: 1, maximum: 500)]
        int $limit = 15,
    ): array {
        return $this->run(static function (Plugin $plugin) use ($query, $limit): array {
            $result = $plugin->getReports()->show($query, $limit, true);
            if ($result === null) {
                throw new ToolCallException(sprintf(
                    'Nothing called “%s” in the map. Give a template path relative to templates/, or an entry type, section or Matrix field handle — component-map.overview lists them.',
                    $query,
                ));
            }
            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'component-map.impact',
        description: 'What changing these files affects: the templates above them, the page-builder blocks rendered through them, the pages that reach them (`wholePages`: every entry of the section, not only those with a block), and `entriesToCheck` — the entries to open in a browser, with URLs. Project config changes count through the content model; deleted templates through whatever still references them. Files that are not templates are listed as `unmapped`.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function impact(
        #[Schema(definition: self::FILES_SCHEMA)]
        array $files = [],
        #[Schema(description: 'Add the files git reports as changed in the project (staged, unstaged, untracked). Needs a git checkout where Craft runs.')]
        bool $git = false,
        #[Schema(description: 'A branch, tag or commit: add everything changed since this branch left it, committed or not — e.g. `main` before a pull request. Needs a git checkout where Craft runs.')]
        string $since = '',
        #[Schema(description: 'Most entries to list. Default 15.', minimum: 1, maximum: 500)]
        int $limit = 15,
    ): array {
        $paths = array_values(array_filter($files, 'is_string'));
        return $this->run(static fn(Plugin $plugin): array => $plugin->getReports()->impact($paths, $git, $since, $limit, true));
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'component-map.unused',
        description: 'What nothing uses: block types no entry contains, templates nothing reaches (whole dead chains included), entry types in no section or Matrix field, and Matrix fields on no entry type. Candidates, not a delete list: templates rendered from PHP, routes or plugins are not seen, and `runtime` lists includes chosen at runtime that could reach anything.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function unused(): array
    {
        return $this->run(static fn(Plugin $plugin): array => $plugin->getReports()->unused());
    }

    /**
     * Checks access, builds the map afresh and turns anything that goes wrong
     * into an error the agent can read — never a failure of the server.
     *
     * @param callable(Plugin): array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function run(callable $report): array
    {
        if (!Plugin::mcpEnabled() || !Craft::$app->getUser()->checkPermission('accessCp')) {
            throw new ToolCallException('Component Map is not available to you here.');
        }

        $plugin = Plugin::getInstance();
        try {
            $plugin->getReports()->fresh();
            $result = $report($plugin);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (ReportException $e) {
            throw new ToolCallException($e->getMessage());
        } catch (\Throwable $e) {
            Craft::warning('Component Map MCP tool failed: ' . $e->getMessage(), 'component-map');
            throw new ToolCallException('Component Map could not build the map: ' . $e->getMessage());
        }

        $error = $plugin->getReports()->entriesError;
        if ($error !== null) {
            $result['entriesError'] = 'Entries could not be read: ' . $error;
        }
        return $result;
    }
}
