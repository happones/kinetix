<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Announcements\Announcement;
use Happones\Kinetix\Announcements\AnnouncementLevels;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AnnouncementData extends Data
{
    public function __construct(
        public int|string|null $id,
        public string $title,
        public string $body,
        public string $level,
        public ?string $publishedAt,
        public bool $isNew,
        /** The level's status color (`kinetix.announcements.levels`). */
        public string $color = 'gray',
        /** The level's icon name; null = the component's own. */
        public ?string $icon = null,
        /** False for a notice the reader must not close. */
        public bool $dismissible = true,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
    ) {}

    public static function fromModel(Announcement $announcement, bool $isNew): self
    {
        $publishedAt = $announcement->published_at;
        $level       = AnnouncementLevels::for($announcement->level);
        $actionUrl   = $announcement->getAttribute('action_url');
        $actionLabel = $announcement->getAttribute('action_label');

        return new self(
            id: $announcement->getKey(),
            title: $announcement->title,
            body: $announcement->body,
            level: $announcement->level,
            publishedAt: $publishedAt instanceof \DateTimeInterface ? $publishedAt->format(\DateTimeInterface::ATOM) : null,
            isNew: $isNew,
            color: $level['color'],
            icon: $level['icon'],
            // A table that predates the column has every row closable.
            dismissible: $announcement->getAttribute('dismissible') !== false
                && $announcement->getAttribute('dismissible')       !== 0,
            // A button needs both halves.
            actionLabel: is_string($actionLabel) && is_string($actionUrl) ? $actionLabel : null,
            actionUrl: is_string($actionLabel)   && is_string($actionUrl) ? $actionUrl : null,
        );
    }
}
