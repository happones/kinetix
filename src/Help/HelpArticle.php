<?php

declare(strict_types=1);

namespace Happones\Kinetix\Help;

/**
 * A discovered help article's metadata (the body is read lazily at render
 * time). Serializable as-is so the discovery pass can be cached.
 */
final class HelpArticle
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $group,
        public readonly ?string $icon,
        public readonly int $order,
        public readonly ?string $permission,
        public readonly string $excerpt,
    ) {}

    /**
     * The summary shape shipped to the frontend (never includes `permission`).
     *
     * @return array{slug: string, title: string, group: ?string, icon: ?string, excerpt: string}
     */
    public function toSummary(): array
    {
        return [
            'slug'    => $this->slug,
            'title'   => $this->title,
            'group'   => $this->group,
            'icon'    => $this->icon,
            'excerpt' => $this->excerpt,
        ];
    }
}
