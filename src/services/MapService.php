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

    /**
     * How to run Craft in this project, for the commands the plugin prints:
     * `ddev craft` when it runs in DDEV, otherwise `php craft`.
     */
    public function craftCommand(): string
    {
        $root = (string)Craft::getAlias('@root');
        return getenv('IS_DDEV_PROJECT') === 'true' || is_file($root . '/.ddev/config.yaml') ? 'ddev craft' : 'php craft';
    }

    /**
     * Splits changed files the way `impact` reports them: templates, content
     * model items from project config (`entryType:hero`), templates left out
     * by the `ignore` setting, and everything else.
     *
     * @param string[] $paths Project- or templates-relative paths.
     * @return array{templates: string[], contentModel: string[], ignored: string[], other: string[]}
     */
    public function classify(array $paths): array
    {
        $out = ['templates' => [], 'contentModel' => [], 'ignored' => [], 'other' => []];
        $configDir = $this->projectConfigDir();
        $templatesDir = $this->templatesPath();
        $ignore = Plugin::getInstance()->getSettings()->ignore;

        foreach ($paths as $path) {
            $item = Explorer::contentModelItem($path, $configDir);
            if ($item !== null) {
                $out['contentModel'][] = $item;
                continue;
            }
            $template = $this->toTemplatePaths([$path])[0] ?? $path;
            $inTemplates = $template !== str_replace('\\', '/', trim($path)) || is_file($templatesDir . '/' . $template);
            if (!$inTemplates && !preg_match('/\.(twig|html)$/i', $template)) {
                $out['other'][] = $path;
                continue;
            }
            foreach ($ignore as $pattern) {
                if (fnmatch($pattern, $template) || fnmatch($pattern, basename($template))) {
                    $out['ignored'][] = $template;
                    continue 2;
                }
            }
            $out['templates'][] = $template;
        }
        return array_map(static fn(array $l) => array_values(array_unique($l)), $out);
    }

    /**
     * The project config folder, relative to the project root.
     */
    public function projectConfigDir(): string
    {
        $root = rtrim(str_replace('\\', '/', (string)Craft::getAlias('@root')), '/');
        $dir = rtrim(str_replace('\\', '/', Craft::$app->getPath()->getProjectConfigPath(false)), '/');
        return str_starts_with($dir, $root . '/') ? substr($dir, strlen($root) + 1) : 'config/project';
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
     * Files changed according to git, project-relative, split into files that
     * exist (`changed`) and files that are gone (`deleted`; a rename is both).
     *
     * Without `$since`: the working tree — staged, unstaged and untracked.
     * With `$since` (a branch, tag or commit): everything that differs from the
     * point where this branch left it — committed on this branch or not yet
     * committed — so `--since=main` is the whole branch as it is right now.
     *
     * @return array{changed: string[], deleted: string[], base: ?string}|null Null when this is not a git checkout.
     * @throws \RuntimeException when `$since` is not a known revision.
     */
    public function gitChanges(?string $since = null): ?array
    {
        $status = $this->git(['status', '--porcelain', '--untracked-files=all']);
        if ($status === null) {
            return null;
        }

        $changed = [];
        $deleted = [];
        $base = null;

        if ($since !== null) {
            $base = $this->git(['merge-base', $since, 'HEAD']);
            if ($base === null || trim($base) === '') {
                throw new \RuntimeException("git does not know “{$since}” (or it shares no history with HEAD).");
            }
            $base = trim($base);
            foreach ($this->lines((string)$this->git(['diff', '--name-status', '-M', $base])) as $line) {
                $parts = explode("\t", $line);
                $code = $parts[0][0] ?? '';
                if ($code === 'R' && count($parts) === 3) {
                    $deleted[] = $parts[1];
                    $changed[] = $parts[2];
                } elseif ($code === 'D') {
                    $deleted[] = $parts[1] ?? '';
                } elseif (isset($parts[1])) {
                    $changed[] = $parts[1];
                }
            }
        }

        foreach ($this->lines($status) as $line) {
            if (strlen($line) < 4) {
                continue;
            }
            $code = substr($line, 0, 2);
            $path = substr($line, 3);
            if (str_contains($path, ' -> ')) {
                [$from, $path] = explode(' -> ', $path, 2);
                $deleted[] = $from;
            }
            if (str_contains($code, 'D')) {
                $deleted[] = $path;
            } elseif ($since === null || $code === '??') {
                $changed[] = $path; // with --since, tracked changes already came from the diff
            }
        }

        $clean = static fn(array $paths): array => array_values(array_unique(array_filter(array_map(static fn($p) => trim($p, '"'), $paths), static fn($p) => $p !== '')));
        $deleted = $clean($deleted);
        $changed = array_values(array_diff($clean($changed), $deleted));

        return ['changed' => $changed, 'deleted' => $deleted, 'base' => $base];
    }

    /**
     * Runs git in the project root. Null when git fails or is not there.
     *
     * @param string[] $args
     */
    private function git(array $args): ?string
    {
        $root = (string)Craft::getAlias('@root');
        $process = @proc_open(['git', '-c', 'core.quotepath=off', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            return null;
        }
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0 ? $output : null;
    }

    /**
     * @return string[]
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(preg_split('/\R/', $output) ?: [], static fn($l) => $l !== ''));
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
