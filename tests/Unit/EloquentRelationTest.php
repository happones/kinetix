<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Support\EloquentRelation;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ErParent extends Model
{
    use SoftDeletes;

    protected $table = 'er_parents';

    public $timestamps = false;

    protected $guarded = [];

    public static int $sideEffects = 0;

    /**
     * @return HasMany<ErChild, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(ErChild::class, 'parent_id');
    }

    /**
     * Untyped, like many app relations are.
     */
    public function untypedChildren()
    {
        return $this->hasMany(ErChild::class, 'parent_id');
    }

    /**
     * @return HasMany<ErChild, $this>|BelongsTo<ErChild, $this>|null
     */
    public function eitherRelation(): HasMany|BelongsTo|null
    {
        return $this->hasMany(ErChild::class, 'parent_id');
    }

    public function label(): string
    {
        static::$sideEffects++;

        return 'label';
    }

    /**
     * Untyped and not a relation: called, then refused.
     */
    public function untypedNotARelation()
    {
        return 'plain';
    }

    public static function staticChildren(): HasMany
    {
        static::$sideEffects++;

        return (new static)->hasMany(ErChild::class, 'parent_id');
    }

    /**
     * @return HasMany<ErChild, $this>
     */
    public function childrenNamed(string $name): HasMany
    {
        static::$sideEffects++;

        return $this->hasMany(ErChild::class, 'parent_id')->where('name', $name);
    }

    /**
     * @return HasMany<ErChild, $this>
     */
    protected function hiddenChildren(): HasMany
    {
        return $this->hasMany(ErChild::class, 'parent_id');
    }

    /**
     * An app override of a framework method stays a framework method.
     */
    public function push()
    {
        static::$sideEffects++;

        return parent::push();
    }
}

class ErChild extends Model
{
    protected $table = 'er_children';

    public $timestamps = false;

    protected $guarded = [];
}

class EloquentRelationTest extends TestCase
{
    private ErParent $parent;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('er_parents', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
        });

        Schema::create('er_children', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id');
            $table->string('name');
        });

        ErParent::$sideEffects = 0;

        $this->parent = ErParent::create(['name' => 'Parent']);
    }

    public function test_it_accepts_typed_and_untyped_relations(): void
    {
        $this->assertInstanceOf(HasMany::class, EloquentRelation::resolve($this->parent, 'children'));
        $this->assertInstanceOf(HasMany::class, EloquentRelation::resolve($this->parent, 'untypedChildren'));
        $this->assertInstanceOf(HasMany::class, EloquentRelation::resolve($this->parent, 'eitherRelation'));
    }

    public function test_it_never_calls_framework_methods(): void
    {
        foreach (['delete', 'forceDelete', 'restore', 'save', 'push', 'touch', 'refresh', 'replicate', 'getKey', 'toArray'] as $method) {
            $this->assertNull(EloquentRelation::resolve($this->parent, $method), $method);
        }

        $this->assertSame(0, ErParent::$sideEffects);
        $this->assertNotNull(ErParent::query()->find($this->parent->getKey()));
        $this->assertNotNull(ErParent::withTrashed()->find($this->parent->getKey()));
    }

    public function test_it_refuses_a_declared_non_relation_return_type_without_calling_it(): void
    {
        $this->assertNull(EloquentRelation::resolve($this->parent, 'label'));
        $this->assertSame(0, ErParent::$sideEffects);
    }

    public function test_it_refuses_static_protected_parameterised_and_unknown_methods(): void
    {
        $this->assertNull(EloquentRelation::resolve($this->parent, 'staticChildren'));
        $this->assertNull(EloquentRelation::resolve($this->parent, 'childrenNamed'));
        $this->assertNull(EloquentRelation::resolve($this->parent, 'hiddenChildren'));
        $this->assertNull(EloquentRelation::resolve($this->parent, 'nothingHere'));
        $this->assertNull(EloquentRelation::resolve($this->parent, ''));
        $this->assertSame(0, ErParent::$sideEffects);
    }

    public function test_an_untyped_method_must_return_a_relation(): void
    {
        $this->assertNull(EloquentRelation::resolve($this->parent, 'untypedNotARelation'));
    }
}
