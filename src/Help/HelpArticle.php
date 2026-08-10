<?php

declare(strict_types=1);

namespace Happones\Kinetix\Help;

/**
 * A discovered help article's metadata for ONE locale (the body is read lazily
 * at render time). `locale` is the language the served file is actually
 * written in and `isFallback` says whether that differs from the requested
 * one — so the UI can mark, warn about, or hide untranslated articles instead
 * of silently showing the wrong language. Serializable as-is so the discovery
 * pass can be cached.
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
        public readonly string $locale = '',
        public readonly bool $isFallback = false,
    ) {}

    /**
     * The summary shape shipped to the frontend (never includes `permission`).
     *
     * @return array{slug: string, title: string, group: ?string, icon: ?string, excerpt: string, locale: string, isFallback: bool}
     */
    public function toSummary(): array
    {
        return [
            'slug'       => $this->slug,
            'title'      => $this->title,
            'group'      => $this->group,
            'icon'       => $this->icon,
            'excerpt'    => $this->excerpt,
            'locale'     => $this->locale,
            'isFallback' => $this->isFallback,
        ];
    }
}
