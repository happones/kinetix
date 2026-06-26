<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Activity\Activity;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ActivityData extends Data
{
    /**
     * @param array{old: array<string, mixed>, attributes: array<string, mixed>} $changes
     */
    public function __construct(
        public int|string|null $id,
        public string $event,
        public ?string $description,
        public ?string $causerName,
        public int|string|null $causerId,
        public ?string $subjectType,
        public int|string|null $subjectId,
        public array $changes,
        public ?string $createdAt,
    ) {}

    public static function fromModel(Activity $activity): self
    {
        /** @var array<string, mixed> $properties */
        $properties = $activity->properties ?? [];

        $causer     = $activity->causer;
        $causerName = $causer instanceof Model ? $causer->getAttribute('name') : null;

        return new self(
            $activity->getKey(),
            (string) $activity->event,
            $activity->description,
            is_string($causerName) ? $causerName : null,
            $activity->causer_id,
            $activity->subject_type,
            $activity->subject_id,
            [
                'old'        => (array) ($properties['old'] ?? []),
                'attributes' => (array) ($properties['attributes'] ?? []),
            ],
            $activity->created_at?->toIso8601String(),
        );
    }
}
