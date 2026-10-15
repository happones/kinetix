<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown\Highlighting;

/**
 * Turns a fenced code block into highlighted HTML. Bind your own
 * implementation in the container to use another engine.
 */
interface CodeHighlighter
{
    /**
     * The block as a `<pre>` element, or null when the language isn't
     * supported: the block then renders as plain, escaped code.
     */
    public function highlight(string $code, string $language): ?string;
}
