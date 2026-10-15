<?php

declare(strict_types=1);

namespace Happones\Kinetix\Docs;

/**
 * A top-level docs section: one directory per locale. Its `index.md` front
 * matter titles it (`title`, `description`, `order`); without one the title is
 * the directory name.
 */
final readonly class DocsSection
{
    /**
     * @param array<int, DocsPage> $pages ordered; the landing page (if any) first
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public int $order,
        public array $pages,
    ) {}

    public function landing(): ?DocsPage
    {
        $first = $this->pages[0] ?? null;

        return $first?->isLanding() ? $first : null;
    }
}
