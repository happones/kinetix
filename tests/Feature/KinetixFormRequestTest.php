<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Http\KinetixFormRequest;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

class StorePostRequest extends KinetixFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function form(): Form
    {
        return Form::make()->schema([
            TextInput::make('title')
                ->label('Post Title')
                ->required()
                ->maxLength(10)
                ->validationMessages(['required' => 'Title is mandatory.']),
            TextInput::make('slug')
                ->required()
                ->dehydrateStateUsing(fn ($state) => strtolower((string) $state)),
            // No rules — must still survive dehydration.
            Toggle::make('is_active'),
            // Never persisted.
            TextInput::make('confirm')->saved(false),
        ]);
    }
}

class StoreCompanyRequest extends KinetixFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function form(): Form
    {
        return Form::make()->schema([
            TextInput::make('type'),
            TextInput::make('company_name')->required()->maxLength(5)->visibleWhen('type', 'company'),
            TextInput::make('vat')->requiredWhen('type', 'company'),
        ]);
    }
}

class KinetixFormRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', HandlePrecognitiveRequests::class])
            ->post('/posts', function (StorePostRequest $request) {
                return response()->json($request->dehydratedState());
            });

        Route::middleware(['web'])
            ->post('/companies', function (StoreCompanyRequest $request) {
                return response()->json($request->dehydratedState());
            });
    }

    public function test_rules_messages_and_attributes_come_from_the_form(): void
    {
        $request = new StorePostRequest;

        $this->assertSame(
            [
                'title'     => ['required', 'max:10'],
                'slug'      => ['required'],
                'is_active' => [],
                'confirm'   => [],
            ],
            $request->rules()
        );
        $this->assertSame(['title.required' => 'Title is mandatory.'], $request->messages());
        $this->assertSame([
            'title'     => 'Post Title',
            'slug'      => 'Slug',
            'is_active' => 'Is Active',
            'confirm'   => 'Confirm',
        ], $request->attributes());
    }

    public function test_validation_failure_uses_form_message(): void
    {
        $this->postJson('/posts', ['slug' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', 'Title is mandatory.');
    }

    public function test_dehydrated_state_applies_hooks_and_drops_unsaved_fields(): void
    {
        $this->postJson('/posts', [
            'title'     => 'Hello',
            'slug'      => 'MY-SLUG',
            'is_active' => true,
            'confirm'   => 'secret',
        ])
            ->assertOk()
            ->assertExactJson([
                'title'     => 'Hello',
                'slug'      => 'my-slug',   // dehydrateStateUsing lowercased it
                'is_active' => true,        // rule-less field kept
                // 'confirm' excluded via saved(false)
            ]);
    }

    public function test_precognition_validates_only_requested_field(): void
    {
        // Only `title` is validated; a missing required `slug` must not error.
        $this->postJson('/posts', ['title' => ''], [
            'Precognition'               => 'true',
            'Precognition-Validate-Only' => 'title',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', 'Title is mandatory.')
            ->assertJsonMissingPath('errors.slug');
    }

    public function test_precognition_success_returns_no_content(): void
    {
        $this->postJson('/posts', ['title' => 'ok'], [
            'Precognition'               => 'true',
            'Precognition-Validate-Only' => 'title',
        ])
            ->assertNoContent()
            ->assertHeader('Precognition-Success', 'true');
    }

    /**
     * Conditional rules read the submitted values. They used to be evaluated
     * against nothing, so a visibleWhen() field the user filled in lost its
     * rules while dehydratedState() still persisted it.
     */
    public function test_a_conditionally_visible_field_is_validated_when_shown(): void
    {
        $this->postJson('/companies', ['type' => 'company', 'company_name' => 'way-longer-than-five', 'vat' => 'X1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_name');
    }

    public function test_a_conditionally_required_field_is_required_when_its_condition_holds(): void
    {
        $this->postJson('/companies', ['type' => 'company', 'company_name' => 'Acme'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vat');
    }

    public function test_a_conditionally_hidden_field_is_neither_validated_nor_persisted(): void
    {
        $this->postJson('/companies', ['type' => 'person', 'company_name' => 'way-longer-than-five'])
            ->assertOk()
            ->assertJsonMissingPath('company_name');
    }
}
