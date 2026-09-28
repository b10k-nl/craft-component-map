<?php

namespace b10k\componentmap\services;

/**
 * Resolves a Twig template name the way Craft does — `_blocks/hero` finds
 * `_blocks/hero.twig`, `_blocks/hero.html`, `_blocks/hero/index.twig` or
 * `_blocks/hero/index.html` — against the files that actually exist.
 *
 * Patterns from dynamic includes (`_adapters/*.twig`) match every file they
 * could name. `*` first matches within one path segment; only if that finds
 * nothing does it span folders.
 *
 * Craft-free: built from a list of paths relative to the templates folder.
 */
final class TemplateResolver
{
    private const EXTENSIONS = ['twig', 'html'];

    /** @var array<string, true> */
    private array $files;

    /**
     * @param string[] $files Paths relative to the templates folder, `/`-separated.
     */
    public function __construct(array $files)
    {
        $this->files = array_fill_keys(array_map(static fn(string $f) => ltrim(str_replace('\\', '/', $f), '/'), $files), true);
        ksort($this->files);
    }

    /**
     * @return string[] Matching files, sorted. Empty when nothing matches, or
     *         for names outside the site templates (`@plugin/…`, `_self`).
     */
    public function resolve(string $name): array
    {
        $name = ltrim(trim($name), '/');
        if ($name === '' || $name === '_self' || str_starts_with($name, '@')) {
            return [];
        }

        if (!str_contains($name, '*')) {
            foreach ($this->candidates($name) as $candidate) {
                if (isset($this->files[$candidate])) {
                    return [$candidate];
                }
            }
            return [];
        }

        // A pattern of only wildcards would match the whole site: not useful.
        if (trim($name, '*/.') === '') {
            return [];
        }

        foreach (['[^/]*', '.*'] as $wildcard) {
            $regex = $this->patternRegex($name, $wildcard);
            $matches = array_values(array_filter(array_keys($this->files), static fn(string $f) => preg_match($regex, $f) === 1));
            if ($matches !== []) {
                sort($matches);
                return $matches;
            }
        }

        return [];
    }

    public function isExternal(string $name): bool
    {
        $name = trim($name);
        return $name === '_self' || str_starts_with($name, '@');
    }

    /**
     * @return string[]
     */
    private function candidates(string $name): array
    {
        // As written first: `_blocks/hero.twig`, `_icons/logo.svg` (source()).
        $out = [$name];
        foreach (self::EXTENSIONS as $e) {
            $out[] = "{$name}.{$e}";
        }
        foreach (self::EXTENSIONS as $e) {
            $out[] = "{$name}/index.{$e}";
        }
        return $out;
    }

    private function patternRegex(string $pattern, string $wildcard): string
    {
        $quoted = implode($wildcard, array_map(static fn(string $p) => preg_quote($p, '#'), explode('*', $pattern)));
        $ext = strtolower(pathinfo($pattern, PATHINFO_EXTENSION));
        $suffix = in_array($ext, self::EXTENSIONS, true) ? '' : '(?:\.(?:twig|html)|/index\.(?:twig|html))';
        return '#^' . $quoted . $suffix . '$#';
    }
}
