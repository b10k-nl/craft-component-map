<?php

namespace b10k\componentmap\services;

use b10k\componentmap\models\Reference;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Lexer;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\Token;

/**
 * Finds every template reference in a Twig file.
 *
 * Uses Twig's own lexer rather than regular expressions, so strings,
 * comments, whitespace control (`{%-`) and `{% verbatim %}` behave exactly as
 * Twig sees them. The lexer needs no extensions: a file using Craft tags
 * (`{% cache %}`, `{% js %}`) or other plugins' filters tokenizes fine — only
 * the parser would need them, and we never parse.
 *
 * If a file does not tokenize (a genuine syntax error), a conservative
 * regex pass still picks up the static references, and the error is reported.
 *
 * Craft-free: unit-tested with plain strings.
 */
final class TwigScanner
{
    /** Tags that name a template, and the keywords that end the template expression. */
    private const TAGS = [
        'include' => ['with', 'only', 'ignore'],
        'embed' => ['with', 'only', 'ignore'],
        'extends' => [],
        'import' => ['as'],
        'from' => ['import'],
        'use' => ['with'],
    ];

    private const FUNCTIONS = ['include', 'source'];

    private Lexer $lexer;

    public function __construct()
    {
        $this->lexer = new Lexer(new Environment(new ArrayLoader([])));
    }

    /**
     * @return array{references: Reference[], error: ?string}
     */
    public function scan(string $code, string $name = 'template'): array
    {
        try {
            $stream = $this->lexer->tokenize(new Source($code, $name));
        } catch (SyntaxError $e) {
            return ['references' => $this->regexFallback($code), 'error' => $e->getRawMessage() . ' (line ' . $e->getTemplateLine() . ')'];
        }

        /** @var Token[] $tokens */
        $tokens = [];
        while (!$stream->isEOF()) {
            $tokens[] = $stream->getCurrent();
            $stream->next();
        }

        $references = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // {% include … %} and friends.
            if ($token->test(Token::BLOCK_START_TYPE) && isset($tokens[$i + 1]) && $tokens[$i + 1]->test(Token::NAME_TYPE)) {
                $tag = (string)$tokens[$i + 1]->getValue();
                if (array_key_exists($tag, self::TAGS)) {
                    $expr = $this->collectTagExpression($tokens, $i + 2, self::TAGS[$tag]);
                    $targets = $this->analyse($expr);
                    if ($targets !== []) {
                        $references[] = new Reference($tag, $tokens[$i + 1]->getLine(), $targets);
                    }
                }
                continue;
            }

            // include('…') / source('…') anywhere in an expression — but not
            // a method call like `craft.app.view.include(…)`.
            if (
                $token->test(Token::NAME_TYPE)
                && in_array($token->getValue(), self::FUNCTIONS, true)
                && isset($tokens[$i + 1])
                && self::sym($tokens[$i + 1], '(')
                && !($i > 0 && self::sym($tokens[$i - 1], '.'))
                && !($i > 0 && $tokens[$i - 1]->test(Token::NAME_TYPE, 'include')) // `{% include(...) %}` handled above
            ) {
                $expr = $this->collectFirstArgument($tokens, $i + 2);
                $targets = $this->analyse($expr);
                if ($targets !== []) {
                    $references[] = new Reference($token->getValue() . '()', $token->getLine(), $targets);
                }
            }
        }

        return ['references' => $references, 'error' => null];
    }

    /**
     * Tokens of the template expression after a tag name, up to the closing
     * `%}` or a keyword (`with`, `only`, …) at nesting depth 0.
     *
     * @param Token[] $tokens
     * @param string[] $stopWords
     * @return Token[]
     */
    private function collectTagExpression(array $tokens, int $start, array $stopWords): array
    {
        $out = [];
        $depth = 0;
        for ($i = $start, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->test(Token::BLOCK_END_TYPE)) {
                break;
            }
            if ($depth === 0 && $t->test(Token::NAME_TYPE) && in_array($t->getValue(), $stopWords, true)) {
                break;
            }
            $depth += $this->depthChange($t);
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Tokens of a function's first argument.
     *
     * @param Token[] $tokens
     * @return Token[]
     */
    private function collectFirstArgument(array $tokens, int $start): array
    {
        $out = [];
        $depth = 0;
        for ($i = $start, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if ($depth === 0 && (self::sym($t, ',') || self::sym($t, ')'))) {
                break;
            }
            if ($t->test(Token::BLOCK_END_TYPE) || $t->test(Token::VAR_END_TYPE)) {
                break;
            }
            $depth += $this->depthChange($t);
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Brackets, dots and `?` are punctuation in older Twig and operators in
     * newer (3.21+); match either.
     *
     * @param string|string[] $values
     */
    private static function sym(Token $t, string|array $values): bool
    {
        return $t->test(Token::PUNCTUATION_TYPE, $values) || $t->test(Token::OPERATOR_TYPE, $values);
    }

    private function depthChange(Token $t): int
    {
        if (self::sym($t, ['(', '[', '{'])) {
            return 1;
        }
        if (self::sym($t, [')', ']', '}'])) {
            return -1;
        }
        return 0;
    }

    /**
     * Turns a template expression into the templates it can name.
     *
     * @param Token[] $tokens
     * @return array<int, array{pattern: string, dynamic: bool, fallback: bool, conditional: bool, expression: string}>
     */
    private function analyse(array $tokens, bool $fallback = false, bool $conditional = false): array
    {
        $tokens = $this->stripParentheses($tokens);
        if ($tokens === []) {
            return [];
        }

        // ['a', 'b'] — Twig tries each in turn.
        if (self::sym($tokens[0], '[') && self::sym(end($tokens), ']')) {
            $targets = [];
            foreach ($this->splitTopLevel(array_slice($tokens, 1, -1), fn(Token $t) => self::sym($t, ',')) as $k => $element) {
                array_push($targets, ...$this->analyse($element, $fallback || $k > 0, $conditional));
            }
            return $targets;
        }

        // cond ? 'a' : 'b'  and  x ?? 'default'
        $question = $this->indexOfTopLevel($tokens, fn(Token $t) => self::sym($t, '?'));
        if ($question !== null) {
            $branches = array_slice($tokens, $question + 1);
            $targets = [];
            foreach ($this->splitTopLevel($branches, fn(Token $t) => self::sym($t, ':')) as $branch) {
                array_push($targets, ...$this->analyse($branch, $fallback, true));
            }
            return $targets;
        }
        $coalesce = $this->indexOfTopLevel($tokens, fn(Token $t) => $t->test(Token::OPERATOR_TYPE, '??'));
        if ($coalesce !== null) {
            return [
                ...$this->analyse(array_slice($tokens, 0, $coalesce), $fallback, true),
                ...$this->analyse(array_slice($tokens, $coalesce + 1), $fallback, true),
            ];
        }

        // 'a/' ~ x ~ '.twig'
        $parts = $this->splitTopLevel($tokens, fn(Token $t) => $t->test(Token::OPERATOR_TYPE, '~'));
        $pattern = '';
        $dynamic = false;
        foreach ($parts as $part) {
            $part = $this->stripParentheses($part);
            if (count($part) === 1 && $part[0]->test(Token::STRING_TYPE)) {
                $pattern .= (string)$part[0]->getValue();
            } else {
                $pattern .= '*';
                $dynamic = true;
            }
        }

        // A lone variable is unresolvable, but still worth recording.
        return [[
            'pattern' => $pattern,
            'dynamic' => $dynamic,
            'fallback' => $fallback,
            'conditional' => $conditional,
            'expression' => $this->text($tokens),
        ]];
    }

    /**
     * @param Token[] $tokens
     * @return Token[]
     */
    private function stripParentheses(array $tokens): array
    {
        while (
            count($tokens) >= 2
            && self::sym($tokens[0], '(')
            && self::sym(end($tokens), ')')
            && $this->matchingClose($tokens) === count($tokens) - 1
        ) {
            $tokens = array_slice($tokens, 1, -1);
        }
        return $tokens;
    }

    /** @param Token[] $tokens */
    private function matchingClose(array $tokens): ?int
    {
        $depth = 0;
        foreach ($tokens as $i => $t) {
            $depth += $this->depthChange($t);
            if ($depth === 0) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @param Token[] $tokens
     * @param callable(Token): bool $isSeparator
     * @return array<int, Token[]>
     */
    private function splitTopLevel(array $tokens, callable $isSeparator): array
    {
        $parts = [[]];
        $depth = 0;
        foreach ($tokens as $t) {
            if ($depth === 0 && $isSeparator($t)) {
                $parts[] = [];
                continue;
            }
            // Interpolated strings ("a#{b}") nest like brackets.
            if ($t->test(Token::INTERPOLATION_START_TYPE)) {
                $depth++;
            } elseif ($t->test(Token::INTERPOLATION_END_TYPE)) {
                $depth--;
            } else {
                $depth += $this->depthChange($t);
            }
            $parts[count($parts) - 1][] = $t;
        }
        return array_values(array_filter($parts, static fn(array $p) => $p !== []));
    }

    /**
     * @param Token[] $tokens
     * @param callable(Token): bool $match
     */
    private function indexOfTopLevel(array $tokens, callable $match): ?int
    {
        $depth = 0;
        foreach ($tokens as $i => $t) {
            if ($depth === 0 && $match($t)) {
                return $i;
            }
            $depth += $this->depthChange($t);
        }
        return null;
    }

    /** @param Token[] $tokens */
    private function text(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $t) {
            $value = (string)$t->getValue();
            if ($t->test(Token::STRING_TYPE)) {
                $out .= "'" . $value . "'";
            } elseif (self::sym($t, ['.', '(', ')', '[', ']', '|'])) {
                $out .= $value;
            } elseif ($t->test(Token::OPERATOR_TYPE) || self::sym($t, [',', '?', ':'])) {
                $out .= ' ' . $value . ' ';
            } else {
                $out .= $value;
            }
        }
        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }

    /**
     * Static references only, for files the lexer rejects.
     *
     * @return Reference[]
     */
    private function regexFallback(string $code): array
    {
        $references = [];
        $patterns = [
            '/\{%-?\s*(include|embed|extends|import|from|use)\s+([\'"])([^\'"]+)\2/',
            '/(?<![.\w])(include|source)\(\s*([\'"])([^\'"]+)\2/',
        ];
        foreach ($patterns as $k => $regex) {
            if (preg_match_all($regex, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches as $m) {
                $line = substr_count(substr($code, 0, (int)$m[0][1]), "\n") + 1;
                $references[] = new Reference($m[1][0] . ($k === 1 ? '()' : ''), $line, [[
                    'pattern' => $m[3][0],
                    'dynamic' => false,
                    'fallback' => false,
                    'conditional' => false,
                    'expression' => "'" . $m[3][0] . "'",
                ]]);
            }
        }
        usort($references, static fn(Reference $a, Reference $b) => $a->line <=> $b->line);
        return $references;
    }
}
