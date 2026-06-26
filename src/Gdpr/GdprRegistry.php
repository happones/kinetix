<?php

declare(strict_types=1);

namespace Happones\Kinetix\Gdpr;

use Closure;

/**
 * Holds the registered "export my data" sections and an optional custom
 * deletion handler for the GDPR module.
 */
class GdprRegistry
{
    /**
     * @var array<string, Closure(mixed): mixed>
     */
    protected array $sections = [];

    /**
     * @var (Closure(mixed): void)|null
     */
    protected ?Closure $deleteUsing = null;

    /**
     * Register a named data section resolved from the authenticated user.
     *
     * @param Closure(mixed): mixed $resolver
     */
    public function export(string $name, Closure $resolver): void
    {
        $this->sections[$name] = $resolver;
    }

    /**
     * @return array<string, Closure(mixed): mixed>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * Fully override how an account is deleted/anonymized.
     *
     * @param Closure(mixed): void $callback
     */
    public function deleteUsing(Closure $callback): void
    {
        $this->deleteUsing = $callback;
    }

    /**
     * @return (Closure(mixed): void)|null
     */
    public function deleteCallback(): ?Closure
    {
        return $this->deleteUsing;
    }
}
