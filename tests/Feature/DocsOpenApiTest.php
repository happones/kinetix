<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Docs\DocsManager;
use Happones\Kinetix\Docs\OpenApi\OpenApiDocument;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

class DocsOpenApiTest extends TestCase
{
    private string $docsPath;

    private string $specPath;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->docsPath = sys_get_temp_dir().'/kinetix-docs-openapi-'.uniqid();
        $this->specPath = $this->docsPath.'/openapi.json';

        $app['config']->set('kinetix.docs.enabled', true);
        $app['config']->set('kinetix.docs.path', $this->docsPath);
        $app['config']->set('kinetix.docs.openapi', ['reference' => $this->specPath]);
        $app['config']->set('kinetix.docs.cache.enabled', false);
        $app['config']->set('app.url', 'https://acme.test');

        File::ensureDirectoryExists($this->docsPath.'/en/guide');
        File::put($this->docsPath.'/en/guide/index.md', "---\nsection: Guide\norder: 1\n---\nHello.");
        File::put($this->specPath, (string) json_encode($this->spec()));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->docsPath);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $product = ['$ref' => '#/components/schemas/Product'];

        return [
            'openapi'  => '3.1.0',
            'info'     => ['title' => 'Acme API', 'version' => '2.1.0', 'description' => 'Manage your **catalog**.'],
            'servers'  => [['url' => '/api/v1']],
            'security' => [['bearerAuth' => []]],
            'tags'     => [['name' => 'Products', 'description' => 'The catalog.']],
            'paths'    => [
                '/products' => [
                    'get' => [
                        'tags'       => ['Products'],
                        'summary'    => 'List products',
                        'parameters' => [['name' => 'page[size]', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Per page | max 100']],
                        'responses'  => ['200' => ['description' => 'The products', 'content' => ['application/json' => ['schema' => ['type' => 'array', 'items' => $product]]]]],
                    ],
                    'post' => [
                        'tags'        => ['Products'],
                        'summary'     => 'Create a product',
                        'requestBody' => ['content' => ['application/json' => ['schema' => $product]]],
                        'responses'   => ['201' => ['description' => 'Created', 'content' => ['application/json' => ['schema' => $product]]]],
                    ],
                ],
                '/products/{id}' => [
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']]],
                    'delete'     => [
                        'tags'       => ['Products'],
                        'summary'    => 'Delete a product',
                        'deprecated' => true,
                        'responses'  => ['204' => ['description' => 'Deleted']],
                    ],
                ],
                '/ping' => [
                    'get' => ['summary' => 'Ping', 'security' => [], 'responses' => ['200' => ['description' => 'Pong']]],
                ],
            ],
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas'         => [
                    'Product' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Named'],
                            [
                                'type'       => 'object',
                                'required'   => ['price'],
                                'properties' => [
                                    'price'      => ['type' => 'number', 'example' => 12.5],
                                    'status'     => ['type' => 'string', 'enum' => ['draft', 'active']],
                                    'dimensions' => ['type' => 'object', 'properties' => ['width' => ['type' => 'integer']]],
                                    'parent'     => ['$ref' => '#/components/schemas/Product'],
                                ],
                            ],
                        ],
                    ],
                    'Named' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'example' => "O'Brien mug"]]],
                ],
            ],
        ];
    }

    private function inertia(string $uri): TestResponse
    {
        return $this->withHeader('X-Inertia', 'true')->withHeader('X-Inertia-Version', '1')->get($uri);
    }

    public function test_the_document_becomes_a_section_with_a_page_per_tag(): void
    {
        $section = app(DocsManager::class)->section('reference');

        $this->assertSame('Acme API', $section?->title);
        $this->assertSame(['', 'products', 'other'], array_map(fn ($page) => $page->slug, $section->pages));
        $this->assertSame(['guide', 'reference'], array_keys(app(DocsManager::class)->sections()));
    }

    public function test_the_landing_page_has_the_base_url_authentication_and_resources(): void
    {
        $html = $this->inertia('/docs/reference')->assertOk()->json('props.docs.page.html');

        $this->assertStringContainsString('<strong>catalog</strong>', $html);
        $this->assertStringContainsString('https://acme.test/api/v1', $html);
        $this->assertStringContainsString('a bearer token in the', $html);
        $this->assertStringContainsString('href="/docs/reference/products"', $html);
        $this->assertStringContainsString('Version 2.1.0', $html);
    }

    public function test_a_tag_page_lists_its_operations_with_parameters_bodies_samples_and_responses(): void
    {
        $response = $this->inertia('/docs/reference/products')->assertOk();
        $html     = $response->json('props.docs.page.html');
        // Table cells sit on their own lines; one line reads easier.
        $tables = (string) preg_replace('/>\s+<(\/?t[dhr]\b)/', '><$1', $html);

        $this->assertSame(
            ['List products', 'Create a product', 'Delete a product'],
            array_column($response->json('props.docs.page.toc'), 'text'),
        );
        // Parameters, with a pipe in a cell kept inside it.
        $this->assertStringContainsString('<code>page[size]</code>', $html);
        $this->assertStringContainsString('Per page | max 100', $html);
        $this->assertStringContainsString('<code>id</code></td><td>path</td><td>string (uuid)</td><td>yes</td>', $tables);
        // The request body: allOf merged, nested objects spelled out, enums listed.
        $this->assertStringContainsString('<code>dimensions.width</code>', $html);
        $this->assertStringContainsString('string: &quot;draft&quot;, &quot;active&quot;', $html);
        $this->assertMatchesRegularExpression('/<code>name<\/code><\/td><td>string<\/td><td>yes</', $tables);
        // Samples in three languages, authenticated, with an example body
        // (read as text: the code is highlighted token by token).
        $code = html_entity_decode(strip_tags($html), ENT_QUOTES);
        $this->assertSame(3, substr_count($html, 'class="kx-code-group"'));
        $this->assertStringContainsString('Authorization: Bearer $API_TOKEN', $code);
        $this->assertStringContainsString("withToken(env('API_TOKEN'))", $code);
        $this->assertStringContainsString("'name' => 'O\\'Brien mug'", $code);
        $this->assertStringContainsString('"name": "O\'\\\'\'Brien mug"', $code);
        // A schema that contains itself is spelled out once: the field names
        // its schema instead of opening it again, and examples leave it out.
        $this->assertStringContainsString('<code>parent</code></td><td>Product</td>', $tables);
        $this->assertStringNotContainsString('parent.', $tables);
        $this->assertStringNotContainsString('"parent"', $code);
        // Deprecation, responses and their examples.
        $this->assertStringContainsString('kx-callout-warning', $html);
        $this->assertStringContainsString('<code>204</code>', $html);
        $this->assertStringContainsString('&quot;price&quot;: 12.5', $html);
    }

    public function test_an_operation_without_security_sends_no_token(): void
    {
        $html = $this->inertia('/docs/reference/other')->assertOk()->json('props.docs.page.html');

        $this->assertStringNotContainsString('Bearer', $html);
    }

    public function test_a_written_page_wins_over_the_generated_one(): void
    {
        File::ensureDirectoryExists($this->docsPath.'/en/reference');
        File::put($this->docsPath.'/en/reference/products.md', "---\ntitle: Our products\n---\nHand-written.");

        $this->inertia('/docs/reference/products')
            ->assertJsonPath('props.docs.page.title', 'Our products')
            ->assertJsonPath('props.docs.page.editUrl', null);

        $this->assertSame(1, collect(app(DocsManager::class)->section('reference')->pages)->where('slug', 'products')->count());
    }

    public function test_the_reference_is_searchable(): void
    {
        $products = collect($this->getJson('/docs/search.json')->json('data'))->firstWhere('url', '/docs/reference/products');

        $this->assertSame('Products', $products['title']);
        $this->assertContains('Create a product', array_column($products['headings'], 'text'));
    }

    public function test_a_yaml_document_reads_like_json(): void
    {
        if (! class_exists(Yaml::class)) {
            $this->markTestSkipped('symfony/yaml is not installed.');
        }

        $yaml = $this->docsPath.'/openapi.yaml';
        File::put($yaml, Yaml::dump($this->spec(), 10));
        config()->set('kinetix.docs.openapi', ['reference' => $yaml]);

        $this->assertSame('Acme API', app(DocsManager::class)->section('reference')?->title);
    }

    public function test_an_unreadable_document_adds_no_section_and_the_check_says_why(): void
    {
        config()->set('kinetix.docs.openapi', ['reference' => $this->docsPath.'/missing.json']);

        $this->assertNull(app(DocsManager::class)->section('reference'));

        $this->artisan('kinetix:docs-check')
            ->expectsOutputToContain('OpenAPI document not found')
            ->assertFailed();
    }

    public function test_tag_slugs_are_kebab_case(): void
    {
        $document = new OpenApiDocument([
            'openapi' => '3.1.0',
            'paths'   => [
                '/a' => ['get' => ['tags' => ['AuthMethod'], 'responses' => []]],
                '/b' => ['get' => ['tags' => ['Order items'], 'responses' => []]],
            ],
        ], 'inline');

        $this->assertSame(['auth-method', 'order-items'], array_column($document->tags(), 'slug'));
    }
}
