<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown\Highlighting;

use Illuminate\Support\Facades\Cache;
use Phiki\Phiki;

/**
 * Server-side highlighting with Phiki (phiki/phiki, optional): TextMate
 * grammars, so the HTML ships already colored and no highlighter runs in the
 * browser. Two themes are rendered at once; the dark one is carried as CSS
 * variables that `<KinetixMarkdownContent>` switches to under `.dark`.
 */
class PhikiHighlighter implements CodeHighlighter
{
    protected Phiki $phiki;

    /**
     * @param array<string, string> $themes `light` and `dark` Phiki theme names
     */
    public function __construct(
        protected array $themes = ['light' => 'github-light', 'dark' => 'github-dark'],
        ?string $cacheStore = null,
    ) {
        $this->phiki = new Phiki;

        if ($cacheStore !== null) {
            $this->phiki->cache(Cache::store($cacheStore));
        }
    }

    public static function available(): bool
    {
        return class_exists(Phiki::class);
    }

    public function highlight(string $code, string $language): ?string
    {
        if ($language === '' || ! $this->phiki->environment->grammars->has($language)) {
            return null;
        }

        return $this->phiki->codeToHtml($code, $language, $this->themes)->toString();
    }
}
