<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class FormAuthUser extends Authenticatable {}

class FormAuthPost extends Model
{
    protected $table = 'form_auth_posts';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['published' => 'bool'];
}

class FormFieldAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('form_auth_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->boolean('published')->default(false);
        });

        // Policy ability: the "internalNotes" field is only editable while unpublished.
        Gate::define('editInternalNotes', fn ($user, ?FormAuthPost $post = null) => ! $post?->published);

        $this->actingAs(new FormAuthUser);
    }

    public function test_field_is_visible_when_authorized(): void
    {
        $post = FormAuthPost::create(['title' => 'Draft', 'published' => false]);

        $data = TextInput::make('internalNotes')->authorize('editInternalNotes')->toData('edit', $post);

        $this->assertNotNull($data);
    }

    public function test_field_is_hidden_when_unauthorized(): void
    {
        $post = FormAuthPost::create(['title' => 'Live', 'published' => true]);

        $data = TextInput::make('internalNotes')->authorize('editInternalNotes')->toData('edit', $post);

        $this->assertNull($data);
    }

    public function test_authorize_without_a_record_defers_to_visible_on_create(): void
    {
        // Same lifecycle as Action::authorize(): a record-dependent ability with
        // no explicit subject can't be evaluated yet at create-time, so it defers.
        $data = TextInput::make('internalNotes')->authorize('editInternalNotes')->toData('create', null);

        $this->assertNotNull($data);
    }

    public function test_authorize_accepts_an_explicit_subject(): void
    {
        $published = FormAuthPost::create(['title' => 'Live', 'published' => true]);

        $data = TextInput::make('internalNotes')
            ->authorize('editInternalNotes', $published)
            ->toData('create', null);

        $this->assertNull($data);
    }

    public function test_authorize_accepts_a_closure(): void
    {
        $this->assertNull(TextInput::make('x')->authorize(fn () => false)->toData('create', null));
        $this->assertNotNull(TextInput::make('x')->authorize(fn () => true)->toData('create', null));
    }

    public function test_authorize_accepts_a_bare_boolean(): void
    {
        $this->assertNull(TextInput::make('x')->authorize(false)->toData('create', null));
        $this->assertNotNull(TextInput::make('x')->authorize(true)->toData('create', null));
    }

    public function test_unauthorized_fields_are_dropped_from_validation_rules(): void
    {
        $post = FormAuthPost::create(['title' => 'Live', 'published' => true]);

        $form = Form::make($post)
            ->operation('edit')
            ->schema([
                TextInput::make('title')->required(),
                TextInput::make('internalNotes')->authorize('editInternalNotes')->required(),
            ]);

        $this->assertSame(['title' => ['required']], $form->getValidationRules());
    }
}
