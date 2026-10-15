<?php

declare(strict_types=1);

namespace Happones\Kinetix\Docs\OpenApi;

/**
 * Writes an OpenAPI document as docs markdown, so a reference page renders
 * like any other docs page (headings in the table of contents, highlighted
 * examples, tabbed code samples, search):
 *
 * - the landing page: the API's description, base URL and authentication,
 *   and its resources;
 * - one page per tag: every operation with its parameters, request body,
 *   responses and a request in curl, PHP and JavaScript.
 *
 * Text from the document is markdown (as OpenAPI defines it); the renderer
 * strips raw HTML from it like from any page.
 */
final class OpenApiMarkdown
{
    /** How deep nested objects are spelled out in a schema table. */
    private const DEPTH = 3;

    public function __construct(private OpenApiDocument $document) {}

    /**
     * @param callable(string): string $tagUrl a tag slug's page link
     */
    public function landing(callable $tagUrl): string
    {
        $info  = $this->document->info();
        $lines = [];

        if (trim((string) ($info['description'] ?? '')) !== '') {
            $lines[] = trim((string) $info['description']);
        }

        $lines[] = "## Base URL\n\n```text\n".$this->document->baseUrl()."\n```";

        $schemes = (array) ($this->document->spec['components']['securitySchemes'] ?? []);

        if ($schemes !== []) {
            $auth = ['## Authentication'];

            foreach ($schemes as $name => $scheme) {
                $scheme = $this->document->resolve((array) $scheme);
                $auth[] = '- **'.$this->inline((string) $name).'** — '.$this->schemeLabel($scheme)
                    .(trim((string) ($scheme['description'] ?? '')) !== '' ? '. '.$this->inline((string) $scheme['description']) : '');
            }

            $lines[] = implode("\n", $auth);
        }

        $resources = ["## Resources\n", '| Resource | Operations |', '|---|---|'];

        foreach ($this->document->tags() as $tag) {
            $resources[] = '| ['.$this->cell($tag['name']).']('.$tagUrl($tag['slug']).') | '.count($tag['operations']).' |';
        }

        $lines[] = implode("\n", $resources);

        if (is_string($info['version'] ?? null)) {
            $lines[] = '*Version '.$this->inline($info['version']).'*';
        }

        return implode("\n\n", $lines)."\n";
    }

    /**
     * @param array{name: string, slug: string, description: string, operations: array<int, array<string, mixed>>} $tag
     */
    public function tag(array $tag): string
    {
        $blocks = [];

        if (trim($tag['description']) !== '') {
            $blocks[] = trim($tag['description']);
        }

        foreach ($tag['operations'] as $operation) {
            $blocks[] = $this->operation($operation);
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function operation(array $operation): string
    {
        $method = strtoupper((string) $operation['method']);
        $path   = (string) $operation['path'];
        $title  = trim((string) ($operation['summary'] ?? ''));
        // Without a summary the method and path are the heading themselves.
        $lines = $title !== ''
            ? ['## '.$this->inline($title), '`'.$method.'` `'.$path.'`']
            : ['## '.$method.' '.$this->inline($path)];

        if (! empty($operation['deprecated'])) {
            $lines[] = "> [!WARNING] Deprecated\n> This operation is going away; avoid it in new code.";
        }

        if (trim((string) ($operation['description'] ?? '')) !== '') {
            $lines[] = trim((string) $operation['description']);
        }

        $parameters = $this->parameters($operation);

        if ($parameters !== '') {
            $lines[] = "**Parameters**\n\n".$parameters;
        }

        [$contentType, $bodySchema] = $this->requestBody($operation);

        if ($bodySchema !== null) {
            $lines[] = '**Request body** (`'.$contentType."`)\n\n".$this->schemaTable($bodySchema);
        }

        $lines[] = $this->samples($operation, $method, $path, $contentType, $bodySchema);
        $lines[] = $this->responses($operation);

        return implode("\n\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function parameters(array $operation): string
    {
        $rows = [];

        foreach ((array) $operation['parameters'] as $parameter) {
            $parameter = $this->document->resolve((array) $parameter);

            if (! is_string($parameter['name'] ?? null)) {
                continue;
            }

            $schema = $this->document->resolve((array) ($parameter['schema'] ?? []));
            $rows[] = '| `'.$this->cell($parameter['name']).'` | '.$this->cell((string) ($parameter['in'] ?? '')).' | '
                .$this->cell($this->typeLabel($schema)).' | '.(! empty($parameter['required']) ? 'yes' : '').' | '
                .$this->cell((string) ($parameter['description'] ?? $schema['description'] ?? '')).' |';
        }

        return $rows === [] ? '' : "| Name | In | Type | Required | Description |\n|---|---|---|---|---|\n".implode("\n", $rows);
    }

    /**
     * @param  array<string, mixed>                           $operation
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function requestBody(array $operation): array
    {
        $body    = $this->document->resolve((array) ($operation['requestBody'] ?? []));
        $content = (array) ($body['content'] ?? []);

        if ($content === []) {
            return ['application/json', null];
        }

        $type = array_key_exists('application/json', $content) ? 'application/json' : (string) array_key_first($content);

        return [$type, (array) ($content[$type]['schema'] ?? [])];
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function responses(array $operation): string
    {
        $rows     = [];
        $examples = [];

        foreach ((array) ($operation['responses'] ?? []) as $status => $response) {
            $response = $this->document->resolve((array) $response);
            $rows[]   = '| `'.$status.'` | '.$this->cell((string) ($response['description'] ?? '')).' |';
            $content  = (array) ($response['content'] ?? []);
            $type     = array_key_exists('application/json', $content) ? 'application/json' : array_key_first($content);

            if ($type !== null && str_contains((string) $type, 'json')) {
                $media   = (array) $content[$type];
                $example = $media['example'] ?? $this->firstExample($media) ?? $this->example((array) ($media['schema'] ?? []));

                if ($example !== null) {
                    $examples[] = "```json [{$status}]\n".json_encode($example, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n```";
                }
            }
        }

        if ($rows === []) {
            return '';
        }

        return "**Responses**\n\n| Status | Description |\n|---|---|\n".implode("\n", $rows)
            .($examples !== [] ? "\n\n".implode("\n\n", $examples) : '');
    }

    /**
     * A request in curl, PHP (Laravel's HTTP client) and JavaScript, with
     * the operation's authentication and an example body.
     *
     * @param array<string, mixed>      $operation
     * @param array<string, mixed>|null $bodySchema
     */
    private function samples(array $operation, string $method, string $path, string $contentType, ?array $bodySchema): string
    {
        $url     = $this->document->baseUrl().$path;
        $headers = ['Accept' => str_contains($contentType, 'json') ? $contentType : 'application/json'];
        $bearer  = false;

        foreach ($this->document->securityFor($operation) as $scheme) {
            if (($scheme['type'] ?? null) === 'http' && strtolower((string) ($scheme['scheme'] ?? '')) === 'bearer') {
                $bearer = true;
            } elseif (($scheme['type'] ?? null) === 'apiKey' && ($scheme['in'] ?? null) === 'header' && is_string($scheme['name'] ?? null)) {
                $headers[$scheme['name']] = '$API_KEY';
            }

            break;
        }

        $body = $bodySchema !== null ? $this->example($bodySchema) : null;
        $json = $body       !== null ? json_encode($body, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null;

        if ($json !== null) {
            $headers['Content-Type'] = $contentType;
        }

        // curl
        $curl = ['curl'.($method !== 'GET' ? " -X {$method}" : '')." \"{$url}\""];

        if ($bearer) {
            $curl[] = '  -H "Authorization: Bearer $API_TOKEN"';
        }

        foreach ($headers as $name => $value) {
            $curl[] = "  -H \"{$name}: {$value}\"";
        }

        if ($json !== null) {
            $curl[] = "  -d '".str_replace("'", "'\\''", $json)."'";
        }

        // PHP (Laravel's HTTP client)
        $phpHeaders = [];

        foreach (array_diff_key($headers, ['Content-Type' => true]) as $name => $value) {
            $phpHeaders[] = $this->php($name).' => '.($value === '$API_KEY' ? "env('API_KEY')" : $this->php($value));
        }

        $calls = [
            ...($bearer ? ["withToken(env('API_TOKEN'))"] : []),
            'withHeaders(['.implode(', ', $phpHeaders).'])',
            strtolower($method).'('.$this->php($url).($body !== null ? ', '.$this->php(json_decode((string) json_encode($body), true), 1) : '').')',
        ];
        $php = '$response = Http::'.implode("\n    ->", $calls).';';

        // JavaScript
        $jsHeaders = $bearer ? ['Authorization' => '`Bearer ${process.env.API_TOKEN}`'] : [];

        foreach ($headers as $name => $value) {
            $jsHeaders[$name] = $value === '$API_KEY' ? 'process.env.API_KEY' : "'{$value}'";
        }

        $js = "const response = await fetch('{$url}', {\n  method: '{$method}',\n  headers: {\n"
            .implode("\n", array_map(static fn (string $name, string $value): string => "    '{$name}': {$value},", array_keys($jsHeaders), $jsHeaders))
            ."\n  },".($json !== null ? "\n  body: JSON.stringify(".preg_replace('/\n/', "\n  ", $json).'),' : '')."\n});";

        return "```bash [curl]\n".implode(" \\\n", $curl)."\n```\n\n```php [PHP]\n{$php}\n```\n\n```js [JavaScript]\n{$js}\n```";
    }

    /**
     * A schema as a table of fields; nested objects (and arrays of them) are
     * spelled out as `parent.child` / `items[].child`.
     *
     * @param array<string, mixed> $schema
     */
    private function schemaTable(array $schema): string
    {
        $rows = $this->fields($schema, '', 0);

        if ($rows === []) {
            return '`'.$this->cell($this->typeLabel($schema)).'`';
        }

        return "| Field | Type | Required | Description |\n|---|---|---|---|\n".implode("\n", $rows);
    }

    /**
     * @param  array<string, mixed> $schema unresolved: a `$ref` already being
     *                                      spelled out (a schema that contains
     *                                      itself) isn't opened again
     * @param  array<string, true>  $refs   the refs being spelled out
     * @return array<int, string>
     */
    private function fields(array $schema, string $prefix, int $depth, array $refs = []): array
    {
        if (is_string($schema['$ref'] ?? null)) {
            if (isset($refs[$schema['$ref']])) {
                return [];
            }

            $refs[$schema['$ref']] = true;
        }

        $schema = $this->merged($this->document->resolve($schema));

        if (($schema['type'] ?? null) === 'array' || isset($schema['items'])) {
            return $this->fields((array) ($schema['items'] ?? []), $prefix.'[]', $depth, $refs);
        }

        $rows     = [];
        $required = array_flip(array_filter((array) ($schema['required'] ?? []), 'is_string'));

        foreach ((array) ($schema['properties'] ?? []) as $name => $raw) {
            $raw       = (array) $raw;
            $recursive = is_string($raw['$ref'] ?? null) && isset($refs[$raw['$ref']]);
            $property  = $this->merged($this->document->resolve($raw));
            $field     = ($prefix === '' ? '' : ($prefix === '[]' ? '[].' : $prefix.'.')).$name;
            $type      = $recursive ? $this->refName((string) $raw['$ref']) : $this->typeLabel($property);

            $rows[] = '| `'.$this->cell($field).'` | '.$this->cell($type).' | '
                .(isset($required[$name]) ? 'yes' : '').' | '.$this->cell((string) ($property['description'] ?? '')).' |';

            if (! $recursive && $depth + 1 < self::DEPTH && (isset($property['properties']) || isset($property['items']))) {
                $rows = [...$rows, ...$this->fields($raw, $field, $depth + 1, $refs)];
            }
        }

        return $rows;
    }

    /**
     * `#/components/schemas/Product` → `Product`.
     */
    private function refName(string $ref): string
    {
        return (string) substr((string) strrchr($ref, '/'), 1);
    }

    /**
     * `allOf` folded into one schema (properties and required lists joined).
     *
     * @param  array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function merged(array $schema): array
    {
        if (! is_array($schema['allOf'] ?? null)) {
            return $schema;
        }

        $merged = array_diff_key($schema, ['allOf' => true]);

        foreach ($schema['allOf'] as $part) {
            $part = $this->merged($this->document->resolve((array) $part));

            $merged['type'] ??= $part['type'] ?? null;
            $merged['properties'] = [...(array) ($merged['properties'] ?? []), ...(array) ($part['properties'] ?? [])];
            $merged['required']   = [...(array) ($merged['required'] ?? []), ...(array) ($part['required'] ?? [])];
        }

        return array_filter($merged, static fn ($value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function typeLabel(array $schema): string
    {
        $schema = $this->merged($this->document->resolve($schema));

        foreach (['oneOf', 'anyOf'] as $key) {
            if (is_array($schema[$key] ?? null)) {
                return implode(' | ', array_map(fn ($each): string => $this->typeLabel((array) $each), $schema[$key]));
            }
        }

        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : 'any');

        if (is_array($type)) {
            $type = implode(' | ', $type);
        }

        $label = match (true) {
            $type === 'array'        => 'array of '.$this->typeLabel((array) ($schema['items'] ?? [])),
            isset($schema['format']) => $type.' ('.$schema['format'].')',
            default                  => (string) $type,
        };

        if (is_array($schema['enum'] ?? null)) {
            $label .= ': '.implode(', ', array_map(static fn ($value): string => json_encode($value) ?: '', $schema['enum']));
        }

        return $label.(! empty($schema['nullable']) ? ', nullable' : '');
    }

    /**
     * An example value for a schema: its own `example`, else one built from
     * its type.
     *
     * @param array<string, mixed> $schema unresolved (see fields())
     * @param array<string, true>  $refs
     */
    private function example(array $schema, int $depth = 0, array $refs = []): mixed
    {
        if (is_string($schema['$ref'] ?? null)) {
            $refs[$schema['$ref']] = true;
        }

        $schema = $this->merged($this->document->resolve($schema));

        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }

        if (is_array($schema['examples'] ?? null) && $schema['examples'] !== []) {
            return array_values($schema['examples'])[0];
        }

        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        if (is_array($schema['enum'] ?? null) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        foreach (['oneOf', 'anyOf'] as $key) {
            if (is_array($schema[$key] ?? null) && $schema[$key] !== []) {
                return $this->example((array) $schema[$key][0], $depth, $refs);
            }
        }

        if ($depth > 5) {
            return null;
        }

        $type = is_array($schema['type'] ?? null) ? ($schema['type'][0] ?? null) : ($schema['type'] ?? null);

        return match (true) {
            // A property that would contain its own schema again is left out.
            $type === 'object' || isset($schema['properties']) => (object) array_map(
                fn ($property) => $this->example((array) $property, $depth + 1, $refs),
                array_filter(
                    (array) ($schema['properties'] ?? []),
                    static fn ($property): bool => ! (is_array($property) && is_string($property['$ref'] ?? null) && isset($refs[$property['$ref']])),
                ),
            ),
            $type === 'array'   => array_filter([$this->example((array) ($schema['items'] ?? []), $depth + 1, $refs)], static fn ($item): bool => $item !== null),
            $type === 'integer' => 1,
            $type === 'number'  => 1.5,
            $type === 'boolean' => true,
            $type === 'string'  => match ($schema['format'] ?? null) {
                'date-time'  => '2026-01-01T09:00:00Z',
                'date'       => '2026-01-01',
                'email'      => 'ada@example.com',
                'uuid'       => '3f2b8c1e-7d4a-4e9b-9c2f-1a2b3c4d5e6f',
                'uri', 'url' => 'https://example.com',
                default      => 'string',
            },
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $media
     */
    private function firstExample(array $media): mixed
    {
        foreach ((array) ($media['examples'] ?? []) as $example) {
            $example = $this->document->resolve((array) $example);

            if (array_key_exists('value', $example)) {
                return $example['value'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scheme
     */
    private function schemeLabel(array $scheme): string
    {
        return match ($scheme['type'] ?? null) {
            'http'          => strtolower((string) ($scheme['scheme'] ?? '')) === 'bearer' ? 'a bearer token in the `Authorization` header' : 'HTTP '.$this->inline((string) ($scheme['scheme'] ?? '')).' authentication',
            'apiKey'        => 'an API key in the `'.$this->inline((string) ($scheme['name'] ?? '')).'` '.$this->inline((string) ($scheme['in'] ?? '')),
            'oauth2'        => 'OAuth 2',
            'openIdConnect' => 'OpenID Connect',
            default         => $this->inline((string) ($scheme['type'] ?? '')),
        };
    }

    /**
     * A value as PHP source: short arrays, single-quoted strings.
     */
    private function php(mixed $value, int $indent = 0): string
    {
        if (! is_array($value)) {
            return match (true) {
                $value === null                  => 'null',
                is_bool($value)                  => $value ? 'true' : 'false',
                is_int($value), is_float($value) => (string) json_encode($value),
                default                          => "'".addcslashes((string) $value, "'\\")."'",
            };
        }

        if ($value === []) {
            return '[]';
        }

        $pad   = str_repeat('    ', $indent + 1);
        $list  = array_is_list($value);
        $items = array_map(
            fn ($key, $item): string => $pad.($list ? '' : $this->php($key).' => ').$this->php($item, $indent + 1),
            array_keys($value),
            $value,
        );

        return "[\n".implode(",\n", $items).",\n".str_repeat('    ', $indent).']';
    }

    /**
     * One line of text that can't break the markdown it lands in.
     */
    private function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function cell(string $text): string
    {
        return str_replace('|', '\|', $this->inline($text));
    }
}
