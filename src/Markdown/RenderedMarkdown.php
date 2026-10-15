<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown;

/**
 * A rendered markdown document: safe HTML for `<KinetixMarkdownContent>`, its
 * table of contents (h2/h3) and its plain text (for search and excerpts).
 */
final readonly class RenderedMarkdown
{
    /**
     * @param array<int, array{level: int, id: string, text: string}> $toc
     */
    public function __construct(
        public string $html,
        public array $toc,
        public string $text,
    ) {}

    /**
     * @return array{html: string, toc: array<int, array{level: int, id: string, text: string}>, text: string}
     */
    public function toArray(): array
    {
        return ['html' => $this->html, 'toc' => $this->toc, 'text' => $this->text];
    }

    /**
     * @param array{html: string, toc: array<int, array{level: int, id: string, text: string}>, text: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['html'], $data['toc'], $data['text']);
    }
}
