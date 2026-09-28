<?php

namespace b10k\componentmap\models;

/**
 * One place where a template pulls in another: `{% include %}`, `{% embed %}`,
 * `{% extends %}`, `{% import %}`, `{% from %}`, `{% use %}`, `include()`,
 * `source()`.
 *
 * A reference can name several templates:
 *
 * - `{% include ['a', 'b'] %}` — `b` is a fallback (used if `a` is missing);
 * - `{% extends x ? 'a' : 'b' %}` — both are conditional;
 * - `{% include '_adapters/' ~ block.type.handle ~ '.twig' %}` — a dynamic
 *   pattern, `_adapters/*.twig`, resolved against the files that exist.
 */
final class Reference
{
    /**
     * @param string $tag include | embed | extends | import | from | use | include() | source()
     * @param array<int, array{pattern: string, dynamic: bool, fallback: bool, conditional: bool, expression: string}> $targets
     */
    public function __construct(
        public readonly string $tag,
        public readonly int $line,
        public readonly array $targets,
    ) {
    }
}
