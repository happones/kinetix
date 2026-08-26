<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PageData extends Data
{
    /**
     * A page's chrome: its title and its two action bars. Deliberately nothing
     * about the BODY — a page declares the actions around its content, and the
     * content itself stays whatever the Vue page renders.
     *
     * @param array<int, ActionData> $headerActions Actions in the page header
     * @param array<int, ActionData> $footerActions Actions in the page footer
     * @param bool                   $stickyFooter  Pin the footer bar to the bottom of the scroll container
     */
    public function __construct(
        public ?string $heading,
        public ?string $description,
        public array $headerActions,
        public array $footerActions,
        public bool $stickyFooter,
    ) {}
}
