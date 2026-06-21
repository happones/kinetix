<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Data\ImportOptionsData;
use Happones\Kinetix\Imports\FileReader;
use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Imports\Jobs\ImportProcessor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;

class RuledImporter extends Importer
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('email')->rules(['email']),
            ImportColumn::make('phone'),
        ];
    }
}

class SampleImporter extends Importer
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')->guess(['nombre'])->requiredMapping(),
            ImportColumn::make('email')->guess(['e-mail']),
            ImportColumn::make('phone')->guess(['celular']),
        ];
    }
}

class ImportTest extends TestCase
{
    public function test_header_matching_is_normalized(): void
    {
        $column = ImportColumn::make('name')->guess(['nombre']);

        $this->assertTrue($column->matchesHeader('NOMBRE'));
        $this->assertTrue($column->matchesHeader('Name '));
        $this->assertFalse($column->matchesHeader('address'));
    }

    public function test_guess_mapping_is_collision_free(): void
    {
        $map = SampleImporter::guessMapping(['NOMBRE', 'E-mail', 'CELULAR', 'Extra']);

        $this->assertSame(['name' => 0, 'email' => 1, 'phone' => 2], $map);
    }

    public function test_required_columns_are_reported(): void
    {
        $this->assertSame(['name'], SampleImporter::getRequiredColumns());
    }

    public function test_csv_reader_honours_options(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kx').'.csv';
        file_put_contents($path, "junk line\nNOMBRE;Email\nOmar;o@x.com\n");

        $read = FileReader::read($path, new ImportOptionsData(delimiter: ';', skipLines: 1, hasHeader: true));

        $this->assertSame(['NOMBRE', 'Email'], $read['headers']);
        $this->assertSame([['Omar', 'o@x.com']], $read['rows']);

        @unlink($path);
    }

    public function test_cast_state_transforms_value(): void
    {
        $column = ImportColumn::make('email')->castStateUsing(fn ($v) => strtolower((string) $v));

        $this->assertSame('foo@x.com', $column->castState('FOO@X.COM'));
    }

    public function test_csv_reader_skips_trailing_empty_lines(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kx').'.csv';
        file_put_contents($path, "name\nOmar\n\n"); // trailing blank line

        $read = FileReader::read($path, new ImportOptionsData());

        $this->assertSame(['name'], $read['headers']);
        $this->assertSame([['Omar']], $read['rows']);

        @unlink($path);
    }

    public function test_mapped_rules_only_cover_mapped_columns_with_rules(): void
    {
        $rules = $this->invokeMappedRules(['email' => 1]); // email mapped, has a rule

        $this->assertSame(['email' => ['email']], $rules);
    }

    public function test_mapped_rules_exclude_columns_mapped_to_null(): void
    {
        // A column mapped to null is treated as unmapped (isset() is false), so no rules.
        $this->assertSame([], $this->invokeMappedRules(['email' => null]));
    }

    /**
     * @param array<string, int|null> $mapping
     * @return array<string, array<int, mixed>>
     */
    private function invokeMappedRules(array $mapping): array
    {
        $job = new ImportProcessor(RuledImporter::class, '/tmp/x.csv', [], $mapping);
        $method = new ReflectionMethod($job, 'mappedRules');

        return $method->invoke($job, new RuledImporter());
    }
}
