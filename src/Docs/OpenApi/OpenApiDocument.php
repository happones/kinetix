<?php

declare(strict_types=1);

namespace Happones\Kinetix\Docs\OpenApi;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * An OpenAPI 3.x document read from a file — JSON, or YAML when symfony/yaml
 * is installed — with its operations grouped by tag and `$ref`s resolved on
 * demand. What a Scramble export (`php artisan scramble:export`) or any other
 * generator writes.
 */
final class OpenApiDocument
{
    public const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    /**
     * @param array<string, mixed> $spec
     */
    public function __construct(public readonly array $spec, public readonly string $path) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("OpenAPI document not found: {$path}");
        }

        $contents = (string) file_get_contents($path);
        $spec     = str_ends_with(strtolower($path), '.json')
            ? json_decode($contents, true)
            : self::parseYaml($contents, $path);

        if (! is_array($spec) || ! is_string($spec['openapi'] ?? null)) {
            throw new RuntimeException("Not an OpenAPI 3 document: {$path}");
        }

        return new self($spec, $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return is_array($this->spec['info'] ?? null) ? $this->spec['info'] : [];
    }

    /**
     * The base URL of the first server: relative ones are resolved against
     * the app URL.
     */
    public function baseUrl(): string
    {
        $url = (string) ($this->spec['servers'][0]['url'] ?? '');

        if ($url === '' || str_starts_with($url, '/')) {
            $url = rtrim((string) config('app.url'), '/').$url;
        }

        return rtrim($url, '/');
    }

    /**
     * Tags in the document's order (its `tags` list first, then any an
     * operation names), each with its operations. Untagged operations go
     * under "Other".
     *
     * @return array<string, array{name: string, slug: string, description: string, operations: array<int, array<string, mixed>>}>
     */
    public function tags(): array
    {
        $tags = [];

        foreach ((array) ($this->spec['tags'] ?? []) as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null)) {
                $tags[$tag['name']] = $this->tag($tag['name'], (string) ($tag['description'] ?? ''));
            }
        }

        foreach ((array) ($this->spec['paths'] ?? []) as $path => $item) {
            if (! is_array($item)) {
                continue;
            }

            $item = $this->resolve($item);

            foreach (self::METHODS as $method) {
                if (! is_array($item[$method] ?? null)) {
                    continue;
                }

                $operation = $item[$method];
                $names     = array_values(array_filter((array) ($operation['tags'] ?? []), 'is_string')) ?: ['Other'];
                $name      = $names[0];

                $tags[$name] ??= $this->tag($name, '');
                $tags[$name]['operations'][] = [
                    ...$operation,
                    'method'     => $method,
                    'path'       => (string) $path,
                    'parameters' => [...(array) ($item['parameters'] ?? []), ...(array) ($operation['parameters'] ?? [])],
                ];
            }
        }

        return array_filter($tags, static fn (array $tag): bool => $tag['operations'] !== []);
    }

    /**
     * The security schemes an operation needs (its own `security`, else the
     * document's), resolved to their definitions.
     *
     * @param  array<string, mixed>             $operation
     * @return array<int, array<string, mixed>>
     */
    public function securityFor(array $operation): array
    {
        $requirements = $operation['security'] ?? $this->spec['security'] ?? [];
        $schemes      = (array) ($this->spec['components']['securitySchemes'] ?? []);
        $resolved     = [];

        foreach ((array) $requirements as $requirement) {
            foreach (array_keys((array) $requirement) as $name) {
                if (is_array($schemes[$name] ?? null)) {
                    $resolved[] = ['name' => $name, ...$this->resolve($schemes[$name])];
                }
            }
        }

        return $resolved;
    }

    /**
     * Follows `$ref` pointers into the document until a definition is
     * reached; a pointer to itself (a cycle) stops at an empty schema.
     *
     * @param  array<string, mixed> $node
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    public function resolve(array $node, array $seen = []): array
    {
        $ref = $node['$ref'] ?? null;

        if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
            return $node;
        }

        if (isset($seen[$ref])) {
            return [];
        }

        $target = $this->spec;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return [];
            }

            $target = $target[$segment];
        }

        if (! is_array($target)) {
            return [];
        }

        // Sibling keys (a description next to the $ref) win over the target's.
        $siblings = array_diff_key($node, ['$ref' => true]);

        return [...$this->resolve($target, [...$seen, $ref => true]), ...$siblings];
    }

    /**
     * @return array{name: string, slug: string, description: string, operations: array<int, array<string, mixed>>}
     */
    private function tag(string $name, string $description): array
    {
        // `AuthMethod` → `auth-method`, `Order items` → `order-items`.
        return ['name' => $name, 'slug' => Str::slug(Str::snake($name, '-')) ?: 'other', 'description' => $description, 'operations' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseYaml(string $contents, string $path): ?array
    {
        if (! class_exists(Yaml::class)) {
            throw new RuntimeException("Reading {$path} needs symfony/yaml (composer require symfony/yaml), or export the document as JSON.");
        }

        $spec = Yaml::parse($contents);

        return is_array($spec) ? $spec : null;
    }
}
