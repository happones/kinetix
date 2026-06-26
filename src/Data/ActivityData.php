<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Illuminate\Contracts\Support\Arrayable;
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

    /**
     * Normalizes either the native Kinetix Activity model or spatie's Activity
     * model into one DTO, so the frontend contract is driver-agnostic.
     */
    public static function fromModel(Model $activity): self
    {
        $rawProperties = $activity->getAttribute('properties');

        if ($rawProperties instanceof Arrayable) {
            $rawProperties = $rawProperties->toArray();
        }

        /** @var array<string, mixed> $properties */
        $properties = is_array($rawProperties) ? $rawProperties : [];

        $causer     = $activity->getAttribute('causer');
        $causerName = $causer instanceof Model ? $causer->getAttribute('name') : null;

        $createdAt = $activity->getAttribute('created_at');

        return new self(
            $activity->getKey(),
            (string) $activity->getAttribute('event'),
            $activity->getAttribute('description'),
            is_string($causerName) ? $causerName : null,
            $activity->getAttribute('causer_id'),
            $activity->getAttribute('subject_type'),
            $activity->getAttribute('subject_id'),
            [
                'old'        => (array) ($properties['old'] ?? []),
                'attributes' => (array) ($properties['attributes'] ?? []),
            ],
            $createdAt instanceof \DateTimeInterface ? $createdAt->format(\DateTimeInterface::ATOM) : null,
        );
    }
}
