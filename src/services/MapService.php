<?php

namespace b10k\componentmap\services;

use b10k\componentmap\models\Graph;
use b10k\componentmap\Plugin;
use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;

/**
 * Reads the site's templates and content model and builds the map.
 *
 * Builds from scratch every time: scanning a few hundred templates with the
 * Twig lexer takes well under a second, and a map that is never stale beats a
 * cache that sometimes is.
 */
class MapService extends Component
{
    /** Files that are scanned for references. */
    private const SCANNED = ['twig', 'html'];

    /** Files that are only targets (source('icon.svg')). */
    private const LEAVES = ['svg'];

    private ?Graph $graph = null;

    public function graph(): Graph
    {
        if ($this->graph !== null) {
            return $this->graph;
        }

        $plugin = Plugin::getInstance();
        [$templates, $leaves] = $this->readTemplates($plugin->getSettings()->ignore);
        $structure = $plugin->getCraftStructure()->read();

        return $this->graph = (new GraphBuilder())->build($templates, $structure, $leaves);
    }

    public function explorer(): Explorer
    {
        return new Explorer($this->graph());
    }

    public function templatesPath(): string
    {
        return rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/\\');
    }

    /**
     * Maps paths as typed (project-relative, templates-relative, absolute) to
     * paths relative to the templates folder. Anything outside it is kept as
     * given and ends up in `unmapped`.
     *
     * @param string[] $paths
     * @return string[]
     */
    public function toTemplatePaths(array $paths): array
    {
        $root = rtrim((string)Craft::getAlias('@root'), '/\\');
        $templates = $this->templatesPath();
        $out = [];
        foreach ($paths as $path) {
            $path = str_replace('\\', '/', trim($path));
            if ($path === '') {
                continue;
            }
            $absolute = str_starts_with($path, '/') ? $path : $root . '/' . $path;
            if (str_starts_with($absolute, $templates . '/')) {
                $out[] = substr($absolute, strlen($templates) + 1);
            } elseif (is_file($templates . '/' . ltrim($path, '/'))) {
                $out[] = ltrim($path, '/');
            } else {
                $out[] = $path;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Files changed in the working tree, according to git (staged, unstaged
     * and untracked). Null when this is not a git checkout.
     *
     * @return string[]|null
     */
    public function gitChangedFiles(): ?array
    {
        $root = (string)Craft::getAlias('@root');
        $process = @proc_open(['git', 'status', '--porcelain', '--untracked-files=all'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            return null;
        }
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            return null;
        }

        $files = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (strlen($line) < 4) {
                continue;
            }
            $path = substr($line, 3);
            if (str_contains($path, ' -> ')) {
                $path = explode(' -> ', $path)[1];
            }
            $files[] = trim($path, '"');
        }
        return $files;
    }

    /**
     * @param string[] $ignore
     * @return array{0: array<string, string>, 1: string[]}
     */
    private function readTemplates(array $ignore): array
    {
        $root = $this->templatesPath();
        $templates = [];
        $leaves = [];
        if (!is_dir($root)) {
            return [$templates, $leaves];
        }

        foreach (FileHelper::findFiles($root, ['only' => array_map(static fn($e) => "*.{$e}", [...self::SCANNED, ...self::LEAVES])]) as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            foreach ($ignore as $pattern) {
                if (fnmatch($pattern, $relative) || fnmatch($pattern, basename($relative))) {
                    continue 2;
                }
            }
            if (in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::LEAVES, true)) {
                $leaves[] = $relative;
                continue;
            }
            $templates[$relative] = (string)file_get_contents($file);
        }
        ksort($templates);
        sort($leaves);
        return [$templates, $leaves];
    }
}
