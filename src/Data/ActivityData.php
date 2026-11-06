<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Activity\ActivityRedactor;
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
     * model into one DTO, so the frontend contract is driver-agnostic. The
     * recorded values pass {@see ActivityRedactor::redactFor()}: whatever
     * reads activity through here never sees a secret, old rows included.
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

        $createdAt   = $activity->getAttribute('created_at');
        $subjectType = $activity->getAttribute('subject_type');
        $subjectType = is_string($subjectType) ? $subjectType : null;

        return new self(
            $activity->getKey(),
            (string) $activity->getAttribute('event'),
            $activity->getAttribute('description'),
            is_string($causerName) ? $causerName : null,
            $activity->getAttribute('causer_id'),
            $subjectType,
            $activity->getAttribute('subject_id'),
            // Redacted on read too: rows written before write-time redaction
            // still hold hidden, encrypted and confidential values.
            [
                'old'        => ActivityRedactor::redactFor($subjectType, (array) ($properties['old'] ?? [])),
                'attributes' => ActivityRedactor::redactFor($subjectType, (array) ($properties['attributes'] ?? [])),
            ],
            $createdAt instanceof \DateTimeInterface ? $createdAt->format(\DateTimeInterface::ATOM) : null,
        );
    }
}
