<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\Checkbox;
use Happones\Kinetix\Forms\Components\CheckboxList;
use Happones\Kinetix\Forms\Components\DatePicker;
use Happones\Kinetix\Forms\Components\DateTimePicker;
use Happones\Kinetix\Forms\Components\FileUpload;
use Happones\Kinetix\Forms\Components\NumberField;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\TagsInput;
use Happones\Kinetix\Forms\Components\Textarea;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\FormRequestFields;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\File as Files;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Process\Process;

enum FrPostStatus: string
{
    case Draft = 'draft';
    case Live  = 'live';
}

class FrStorePostRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title'        => 'required|string|max:120',
            'body'         => ['nullable', 'string', 'max:5000'],
            'email'        => 'required|email',
            'website'      => 'nullable|url',
            'password'     => ['required', Password::min(8), 'confirmed'],
            'status'       => ['required', Rule::enum(FrPostStatus::class)],
            'kind'         => ['sometimes', 'required', Rule::in(['news', 'opinion'])],
            'featured'     => 'boolean',
            'terms'        => 'accepted',
            'published_at' => 'nullable|date',
            'starts_on'    => 'date_format:Y-m-d',
            'views'        => 'integer|min:0|max:1000',
            'cover'        => ['nullable', File::image()],
            'tags'         => 'array',
            'tags.*'       => 'string',
            'channels'     => 'array',
            'channels.*'   => 'in:mail,sms',
            'author_id'    => 'required|exists:users,id',
            'items.*.qty'  => 'required|integer',
            'internal'     => 'prohibited',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['title' => 'Headline'];
    }
}

class FrRouteBoundRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['title' => 'unique:posts,title,'.$this->route('post')->id];
    }
}

class FormRequestFieldsTest extends TestCase
{
    /**
     * @return array<string, array{component: string, name: string, calls: list<array{0: string, 1: list<mixed>}>, note: string|null}>
     */
    private function specs(): array
    {
        $byName = [];

        foreach (FormRequestFields::specs(FrStorePostRequest::class) as $spec) {
            $byName[$spec['name']] = $spec;
        }

        return $byName;
    }

    public function test_each_rule_set_becomes_the_input_that_collects_it(): void
    {
        $components = array_map(static fn (array $spec): string => $spec['component'], $this->specs());

        $this->assertSame([
            'title'                 => TextInput::class,
            'body'                  => Textarea::class,
            'email'                 => TextInput::class,
            'website'               => TextInput::class,
            'password'              => TextInput::class,
            'password_confirmation' => TextInput::class,
            'status'                => Select::class,
            'kind'                  => Select::class,
            'featured'              => Toggle::class,
            'terms'                 => Checkbox::class,
            'published_at'          => DateTimePicker::class,
            'starts_on'             => DatePicker::class,
            'views'                 => NumberField::class,
            'cover'                 => FileUpload::class,
            'tags'                  => TagsInput::class,
            'channels'              => CheckboxList::class,
            'author_id'             => TextInput::class,
        ], $components);
    }

    public function test_requirements_lengths_labels_and_choices_carry_over(): void
    {
        $specs = $this->specs();

        $this->assertSame([['required', []], ['maxLength', [120]], ['label', ['Headline']]], $specs['title']['calls']);
        $this->assertSame([['required', []], ['email', []]], $specs['email']['calls']);
        $this->assertSame([['url', []]], $specs['website']['calls']);
        $this->assertSame([['required', []], ['password', []]], $specs['password']['calls']);
        $this->assertSame($specs['password']['calls'], $specs['password_confirmation']['calls']);
        $this->assertSame([['required', []], ['options', [FrPostStatus::class]]], $specs['status']['calls']);
        // `sometimes` makes it optional to send.
        $this->assertSame([['options', [['news' => 'News', 'opinion' => 'Opinion']]]], $specs['kind']['calls']);
        $this->assertSame([['min', [0]], ['max', [1000]]], $specs['views']['calls']);
        $this->assertSame([['image', []]], $specs['cover']['calls']);
        $this->assertSame([['options', [['mail' => 'Mail', 'sms' => 'Sms']]]], $specs['channels']['calls']);
        $this->assertStringContainsString('exists:users,id', (string) $specs['author_id']['note']);
    }

    public function test_the_form_renders_from_the_request(): void
    {
        $schema = Form::fromRequest(FrStorePostRequest::class)->toArray()['schema'];

        $this->assertSame('title', $schema[0]['name']);
        $this->assertSame('Headline', $schema[0]['label']);
        $this->assertCount(17, $schema);
    }

    public function test_rules_that_need_their_route_say_so(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Give the form a Form class instead');

        FormRequestFields::specs(FrRouteBoundRequest::class);
    }

    public function test_only_a_form_request_is_read(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FormRequestFields::specs(Form::class);
    }

    public function test_make_form_writes_a_form_from_the_request(): void
    {
        $path = app_path('Kinetix/Forms/FrPostForm.php');

        try {
            $this->artisan('kinetix:make-form', ['name' => 'FrPostForm', '--from-request' => FrStorePostRequest::class])
                ->assertSuccessful();

            $contents = Files::get($path);

            $this->assertStringContainsString("TextInput::make('title')->required()->maxLength(120)->label('Headline'),", $contents);
            $this->assertStringContainsString("Select::make('status')->required()->options(FrPostStatus::class),", $contents);
            $this->assertStringContainsString("CheckboxList::make('channels')->options(['mail' => 'Mail', 'sms' => 'Sms']),", $contents);
            $this->assertStringContainsString('use '.FrPostStatus::class.';', $contents);
            $this->assertStringContainsString('// exists:users,id', $contents);
            $this->assertStringEndsNotWith("\n\n", $contents);

            // It parses, and the host's Pint leaves it as it is.
            $lint = new Process([PHP_BINARY, '-l', $path]);
            $lint->run();
            $this->assertTrue($lint->isSuccessful(), $lint->getOutput());

            $pint = new Process([dirname(__DIR__, 2).'/vendor/bin/pint', '--test', $path], dirname(__DIR__, 2));
            $pint->run();
            $this->assertTrue($pint->isSuccessful(), $pint->getOutput());
        } finally {
            Files::delete($path);
        }
    }
}
