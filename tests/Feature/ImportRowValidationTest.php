<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Data\ImportOptionsData;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Imports\FileReader;
use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Imports\Jobs\ImportProcessor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RowRecipient extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class RowEmployee extends Model
{
    protected $table = 'row_employees';

    protected $guarded = [];

    public $timestamps = false;
}

class RowEmployeeImporter extends Importer
{
    protected static ?string $model = RowEmployee::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('number')->label('Number'),
            ImportColumn::make('name')->label('Name')->rules('required|string|max:10'),
            ImportColumn::make('email')->label('Email'),
            ImportColumn::make('born_on')->label('Born on'),
            ImportColumn::make('salary')->label('Salary'),
            ImportColumn::make('sdi')->label('SDI'),
            ImportColumn::make('pin')->label('PIN')->sensitive(),
        ];
    }

    public function resolveRecord(array $data): ?Model
    {
        return isset($data['number']) ? RowEmployee::query()->where('number', $data['number'])->first() : null;
    }
}

class PreparedRowImporter extends RowEmployeeImporter
{
    public static int $resolved = 0;

    public function resolveRecord(array $data): ?Model
    {
        static::$resolved++;

        return parent::resolveRecord($data);
    }

    protected function prepareRow(array $row): array
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', (string) ($row['born_on'] ?? ''), $m) === 1) {
            $row['born_on'] = "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return $row;
    }

    protected function rules(array $row, ?Model $record): array
    {
        return [
            // Adds to the column's required|string|max:10.
            'name'    => ['not_in:Nobody'],
            'born_on' => ['nullable', 'date_format:Y-m-d'],
            'email'   => [
                'nullable',
                'email',
                Rule::unique('row_employees', 'email')
                    ->where('team_id', $this->context['team_id'] ?? null)
                    ->ignore($record),
            ],
        ];
    }

    protected function messages(): array
    {
        return ['email.unique' => 'That email belongs to someone else.'];
    }

    protected function after(array $row, ?Model $record): array
    {
        return [function (Validator $validator) use ($row): void {
            if (isset($row['sdi'], $row['salary']) && (float) $row['sdi'] < (float) $row['salary']) {
                $validator->errors()->add('sdi', 'The SDI cannot be below the salary.');
            }
        }];
    }

    public function importRow(array $data): void
    {
        if (($data['number'] ?? null) === 'GONE' && $this->record($data) === null) {
            $this->reject('No employee has that number.');
        }

        $record = $this->record($data) ?? new RowEmployee(['team_id' => $this->context['team_id'] ?? null]);
        $record->fill(array_intersect_key($data, array_flip(['number', 'name', 'email', 'born_on', 'salary', 'sdi'])));
        $record->save();
    }
}

class HalfWayImporter extends RowEmployeeImporter
{
    public function importRow(array $data): void
    {
        DB::table('row_catalogs')->insert(['name' => 'catalog for '.$data['name']]);

        if ($data['name'] === 'Bob') {
            throw new \RuntimeException('The record could not be saved.');
        }

        parent::importRow($data);
    }
}

class RowEmployeeRequest extends FormRequest
{
    public static mixed $seenUser = null;

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'max:10'],
            'email' => ['nullable', 'email', Rule::unique('row_employees', 'email')->ignore($this->route('row_employee'))],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'full name'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            static::$seenUser = $this->user();
        }];
    }
}

class RequestValidatedImporter extends RowEmployeeImporter
{
    protected ?string $validateUsing = RowEmployeeRequest::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('number'),
            ImportColumn::make('name'),
            ImportColumn::make('email'),
        ];
    }
}

class RowEmployeeForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            TextInput::make('name')->label('Full name')->required()->maxLength(10),
            TextInput::make('email')->email(),
        ];
    }
}

class FormValidatedImporter extends RequestValidatedImporter
{
    protected ?string $validateUsing = RowEmployeeForm::class;
}

/**
 * Each row of an import is prepared, validated and imported on its own:
 * whatever a row throws fails that row, never the job.
 */
class ImportRowValidationTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('row_employees', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('team_id')->nullable();
            $table->string('number')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->date('born_on')->nullable();
            $table->decimal('salary')->nullable();
            $table->decimal('sdi')->nullable();
        });

        Schema::create('row_catalogs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Storage::fake('local');
    }

    /**
     * Run an import of $csv (header row first) mapping each header to the
     * column of the same name, and return the notification body.
     *
     * @param class-string<Importer> $importer
     * @param array<string, mixed>   $context
     */
    private function import(string $importer, string $csv, array $context = []): string
    {
        $user = RowRecipient::create(['name' => 'Omar']);
        Storage::disk('local')->put('kinetix-imports/rows.csv', $csv);

        $headers = str_getcsv(strtok($csv, "\n"), ',', '"', '');

        (new ImportProcessor(
            $importer,
            'kinetix-imports/rows.csv',
            ['delimiter' => ',', 'enclosure' => '"', 'skipLines' => 0, 'hasHeader' => true],
            array_flip($headers),
            RowRecipient::class,
            $user->id,
            $context,
        ))->handle();

        return (string) $user->fresh()->notifications()->first()?->data['description'];
    }

    private function failedRowsCsv(): string
    {
        $files = Storage::disk('local')->files('kinetix-exports');

        $this->assertCount(1, $files, 'A failed-rows file should have been published');

        return (string) Storage::disk('local')->get($files[0]);
    }

    public function test_piped_string_rules_validate_instead_of_crashing_the_job(): void
    {
        $body = $this->import(RowEmployeeImporter::class, "name\nAda\nAbcdefghijklmnop\nGrace\n");

        $this->assertSame(['Ada', 'Grace'], RowEmployee::query()->orderBy('id')->pluck('name')->all());
        $this->assertStringContainsString(
            __('validation.max.string', ['attribute' => 'Name', 'max' => 10]),
            $this->failedRowsCsv(),
        );
        $this->assertSame(__('kinetix.import_complete_body', ['imported' => 2, 'failed' => 1]), strtok($body, "\n"));
    }

    public function test_a_rule_that_throws_fails_its_row_not_the_job(): void
    {
        $importer = new class extends RowEmployeeImporter
        {
            public static function getColumns(): array
            {
                return [ImportColumn::make('name')->rules(['required', 'no_such_rule'])];
            }
        };

        $body = $this->import($importer::class, "name\nAda\n");

        $this->assertSame(0, RowEmployee::query()->count());
        $this->assertStringContainsString('validateNoSuchRule', $this->failedRowsCsv());
        $this->assertNotSame('', $body, 'The job finished and notified');
    }

    public function test_rows_are_prepared_before_they_are_validated_and_stored(): void
    {
        $this->import(PreparedRowImporter::class, "number,name,born_on\n1,Ada,15/03/1990\n");

        $this->assertSame('1990-03-15', RowEmployee::query()->value('born_on'));
    }

    public function test_row_rules_see_the_context_and_the_record_the_row_updates(): void
    {
        RowEmployee::create(['team_id' => 7, 'number' => '1', 'name' => 'Ada', 'email' => 'ada@x.test']);
        RowEmployee::create(['team_id' => 7, 'number' => '2', 'name' => 'Grace', 'email' => 'grace@x.test']);
        PreparedRowImporter::$resolved = 0;

        $body = $this->import(
            PreparedRowImporter::class,
            "number,name,email\n1,Ada L,ada@x.test\n3,Cleo,grace@x.test\n",
            ['team_id' => 7],
        );

        // Ada keeps her own email (the unique rule ignores her record); Cleo
        // can't take Grace's.
        $this->assertSame('Ada L', RowEmployee::query()->where('number', '1')->value('name'));
        $this->assertNull(RowEmployee::query()->where('number', '3')->first());
        $this->assertStringContainsString('That email belongs to someone else.', $this->failedRowsCsv());

        // Resolved once per row, shared by the rules and importRow().
        $this->assertSame(2, PreparedRowImporter::$resolved);
    }

    public function test_cross_field_checks_run_and_every_message_is_reported(): void
    {
        $body = $this->import(
            PreparedRowImporter::class,
            "number,name,born_on,salary,sdi\n1,,1990,500,400\n2,Nobody,,,\n3,Abcdefghijklmnop,,,\n",
        );

        $csv = $this->failedRowsCsv();

        $this->assertSame(0, RowEmployee::query()->count());
        $this->assertStringContainsString(__('validation.required', ['attribute' => 'Name']), $csv);
        $this->assertStringContainsString(__('validation.date_format', ['attribute' => 'Born on', 'format' => 'Y-m-d']), $csv);
        $this->assertStringContainsString('The SDI cannot be below the salary.', $csv);
        // Row rules and column rules for the same field both apply.
        $this->assertStringContainsString(__('validation.not_in', ['attribute' => 'Name']), $csv);
        $this->assertStringContainsString(__('validation.max.string', ['attribute' => 'Name', 'max' => 10]), $csv);
    }

    public function test_a_rejected_row_counts_as_failed_not_imported(): void
    {
        $body = $this->import(PreparedRowImporter::class, "number,name\nGONE,Ada\n");

        $this->assertStringContainsString('No employee has that number.', $this->failedRowsCsv());
        $this->assertSame(__('kinetix.import_complete_body', ['imported' => 0, 'failed' => 1]), strtok($body, "\n"));
    }

    public function test_a_row_that_fails_half_way_leaves_nothing_behind(): void
    {
        $this->import(HalfWayImporter::class, "name\nAda\nBob\nCleo\n");

        $this->assertSame(['Ada', 'Cleo'], RowEmployee::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame(
            ['catalog for Ada', 'catalog for Cleo'],
            DB::table('row_catalogs')->orderBy('id')->pluck('name')->all(),
        );
    }

    public function test_the_savepoint_per_row_can_be_switched_off(): void
    {
        config()->set('kinetix.imports.transaction_per_row', false);

        $this->import(HalfWayImporter::class, "name\nAda\nBob\n");

        // One transaction per chunk: Bob's catalog row stays.
        $this->assertSame(2, DB::table('row_catalogs')->count());
    }

    public function test_sensitive_cells_are_blanked_in_the_failed_rows_file(): void
    {
        $this->import(RowEmployeeImporter::class, "name,pin\n,4321\n");

        $csv = $this->failedRowsCsv();

        $this->assertStringNotContainsString('4321', $csv);
    }

    public function test_a_form_request_validates_each_row_as_it_would_on_its_route(): void
    {
        RowEmployee::create(['number' => '1', 'name' => 'Ada', 'email' => 'ada@x.test']);
        RowEmployeeRequest::$seenUser = null;

        $body = $this->import(
            RequestValidatedImporter::class,
            "number,name,email\n1,  Ada L  ,ada@x.test\n2,Bob,ada@x.test\n3,,\n",
        );

        // prepareForValidation() trimmed the name, and the route's record let
        // Ada keep her own email.
        $this->assertSame('Ada L', RowEmployee::query()->where('number', '1')->value('name'));
        $this->assertNull(RowEmployee::query()->where('number', '2')->first());
        $csv = $this->failedRowsCsv();
        $this->assertStringContainsString(__('validation.unique', ['attribute' => 'Email']), $csv);
        // The column label (the header in the user's file) names the field,
        // over the request's attributes().
        $this->assertStringContainsString(__('validation.required', ['attribute' => 'Name']), $csv);

        // $this->user() is whoever started the import.
        $this->assertInstanceOf(RowRecipient::class, RowEmployeeRequest::$seenUser);
    }

    public function test_a_form_class_validates_each_row_with_its_fields(): void
    {
        $body = $this->import(FormValidatedImporter::class, "number,name,email\n1,Ada,not-an-email\n2,Grace,\n");

        $this->assertSame(['Grace'], RowEmployee::query()->pluck('name')->all());
        $this->assertStringContainsString(__('validation.email', ['attribute' => 'Email']), $this->failedRowsCsv());
    }

    public function test_headers_match_without_accents_and_through_aliases(): void
    {
        $column = ImportColumn::make('phone')->label('Teléfono')->aliases(['Celular', 'Tel. personal']);

        $this->assertTrue($column->matchesHeader('Telefono'));
        $this->assertTrue($column->matchesHeader('TELÉFONO'));
        $this->assertTrue($column->matchesHeader('tel personal'));
        $this->assertTrue($column->matchesHeader('celular'));
    }

    public function test_a_label_key_writes_the_template_in_the_users_language_and_maps_any_language(): void
    {
        config()->set('kinetix.locale.locales', ['en' => 'English', 'es' => 'Español']);
        app('translator')->addLines(['employee.phone' => 'Phone'], 'en');
        app('translator')->addLines(['employee.phone' => 'Teléfono'], 'es');

        $column = ImportColumn::make('phone')->labelKey('employee.phone');

        app()->setLocale('es');
        $this->assertSame('Teléfono', $column->getLabel());

        $this->assertTrue($column->matchesHeader('Phone'));
        $this->assertTrue($column->matchesHeader('Telefono'));
        $this->assertFalse($column->matchesHeader('Email'));
    }

    public function test_the_reader_skips_a_utf8_byte_order_mark(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bom');
        file_put_contents($path, "\xEF\xBB\xBFTeléfono,Name\n555,Ada\n");

        $read = FileReader::read($path, new ImportOptionsData);

        $this->assertSame(['Teléfono', 'Name'], $read['headers']);
        $this->assertSame([['555', 'Ada']], $read['rows']);

        @unlink($path);
    }
}
