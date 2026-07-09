<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pdf;

use InvalidArgumentException;

/**
 * Singleton registry of the host's PDF template classes.
 */
class PdfTemplateRegistry
{
    /**
     * @var array<string, class-string<PdfTemplate>>
     */
    protected array $templates = [];

    /**
     * @param class-string<PdfTemplate> $templateClass
     */
    public function register(string $templateClass): void
    {
        if (! is_subclass_of($templateClass, PdfTemplate::class)) {
            throw new InvalidArgumentException($templateClass.' must extend '.PdfTemplate::class);
        }

        $this->templates[$templateClass::key()] = $templateClass;
    }

    public function get(string $key): ?PdfTemplate
    {
        $class = $this->templates[$key] ?? null;

        return $class !== null ? $class::make() : null;
    }

    /**
     * @return array<string, class-string<PdfTemplate>>
     */
    public function all(): array
    {
        return $this->templates;
    }
}
