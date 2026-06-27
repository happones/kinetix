<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;

class Product extends Model
{
    protected $table = 'products';

    public $timestamps = false;

    protected $guarded = [];
}

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
}

class ResourceBreadcrumbsTest extends TestCase
{
    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('products', fn () => 'ok')->name('products.index');
        $router->get('products/{product}', fn () => 'ok')->name('products.show');
        $router->get('products/{product}/edit', fn () => 'ok')->name('products.edit');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
    }

    public function test_route_base_name_and_label_default_from_the_model(): void
    {
        $this->assertSame('products', ProductResource::getRouteBaseName());
        $this->assertSame('Products', ProductResource::getNavigationLabel());
    }

    public function test_index_breadcrumbs_link_to_the_index_route(): void
    {
        $crumbs = ProductResource::breadcrumbs('index');

        $this->assertCount(1, $crumbs);
        $this->assertSame('Products', $crumbs[0]['title']);
        $this->assertStringEndsWith('/products', $crumbs[0]['href']);
    }

    public function test_create_breadcrumbs_append_a_create_crumb(): void
    {
        $crumbs = ProductResource::breadcrumbs('create');

        $this->assertCount(2, $crumbs);
        $this->assertStringEndsWith('/products', $crumbs[0]['href']);
        $this->assertSame(__('kinetix.breadcrumb_create'), $crumbs[1]['title']);
    }

    public function test_edit_breadcrumbs_include_the_record_linking_to_show(): void
    {
        $product = Product::create(['name' => 'Widget']);

        $crumbs = ProductResource::breadcrumbs('edit', $product);

        $this->assertCount(3, $crumbs);
        $this->assertSame('Products', $crumbs[0]['title']);
        $this->assertSame('Widget', $crumbs[1]['title']);
        $this->assertStringEndsWith('/products/'.$product->getKey(), $crumbs[1]['href']);
        $this->assertSame(__('kinetix.breadcrumb_edit'), $crumbs[2]['title']);
    }

    public function test_show_breadcrumbs_end_with_the_record(): void
    {
        $product = Product::create(['name' => 'Gadget']);

        $crumbs = ProductResource::breadcrumbs('show', $product);

        $this->assertCount(2, $crumbs);
        $this->assertSame('Gadget', $crumbs[1]['title']);
    }

    public function test_record_title_falls_back_to_the_key_without_a_name(): void
    {
        $product = Product::create([]);

        $this->assertSame('#'.$product->getKey(), ProductResource::getRecordTitle($product));
    }
}
