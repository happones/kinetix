<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Announcements\Announcement;
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
    ) {}

    public static function fromModel(Announcement $announcement, bool $isNew): self
    {
        $publishedAt = $announcement->published_at;

        return new self(
            id: $announcement->getKey(),
            title: $announcement->title,
            body: $announcement->body,
            level: $announcement->level,
            publishedAt: $publishedAt instanceof \DateTimeInterface ? $publishedAt->format(\DateTimeInterface::ATOM) : null,
            isNew: $isNew,
        );
    }
}
