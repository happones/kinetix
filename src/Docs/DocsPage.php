<?php

declare(strict_types=1);

namespace Happones\Kinetix\Docs;

/**
 * One page of the docs: `{path}/{locale}/{section}/{file}.md`. The slug is the
 * file name without an `NN-` ordering prefix; a section's `index.md` is its
 * landing page (empty slug). A page generated from an OpenAPI document has
 * the document as its `path`.
 */
final readonly class DocsPage
{
    public function __construct(
        public string $section,
        public string $slug,
        public string $title,
        public string $description,
        public string $group,
        public int $order,
        public string $path,
        public string $locale,
        public bool $fallback,
        /** Generated from an OpenAPI document (`path`) rather than written. */
        public bool $openApi = false,
        /** The OpenAPI tag the page lists (null: the section's landing page). */
        public ?string $tag = null,
    ) {}

    public function isLanding(): bool
    {
        return $this->slug === '';
    }

    public function updatedAt(): ?int
    {
        $time = @filemtime($this->path);

        return $time === false ? null : $time;
    }
}
