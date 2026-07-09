<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\DatePicker;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\DateFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DatedRecord extends Model
{
    protected $table = 'dated_records';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['published_at' => 'datetime'];
}

class LocalizedDateFormattingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dated_records', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('published_at');
        });

        DatedRecord::create(['published_at' => '2026-07-09 14:30:00']);
    }

    private function record(): DatedRecord
    {
        return DatedRecord::query()->firstOrFail();
    }

    public function test_date_with_no_format_localizes_to_the_app_locale(): void
    {
        $column = TextColumn::make('published_at')->date();

        app()->setLocale('en');
        $this->assertSame('Jul 9, 2026', $column->getState($this->record()));

        app()->setLocale('es');
        $this->assertSame('9 de jul. de 2026', $column->getState($this->record()));
    }

    public function test_date_time_with_no_format_localizes_and_includes_time(): void
    {
        app()->setLocale('es');

        $state = (string) TextColumn::make('published_at')->dateTime()->getState($this->record());

        $this->assertStringContainsString('2026', $state);
        $this->assertStringContainsString('14:30', $state);
        $this->assertStringContainsString('jul', $state);
    }

    public function test_an_explicit_php_format_stays_non_localized(): void
    {
        app()->setLocale('es');

        // Back-compat: a format argument is a plain PHP format().
        $this->assertSame(
            'Jul 9, 2026',
            TextColumn::make('published_at')->date('M j, Y')->getState($this->record()),
        );
    }

    public function test_iso_date_uses_custom_tokens_and_locale_overrides_the_app(): void
    {
        app()->setLocale('en');

        $column = TextColumn::make('published_at')->isoDate('LL')->locale('fr');

        $this->assertSame('9 juillet 2026', $column->getState($this->record()));
    }

    public function test_the_config_tokens_drive_the_default_format(): void
    {
        config()->set('kinetix.formats.date', 'L'); // numeric: 07/09/2026 (en)
        app()->setLocale('en');

        $this->assertSame(
            '07/09/2026',
            TextColumn::make('published_at')->date()->getState($this->record()),
        );
    }

    public function test_infolist_text_entry_localizes_the_same_way(): void
    {
        app()->setLocale('es');

        $entry = TextEntry::make('published_at')->date();

        $this->assertSame('9 de jul. de 2026', $entry->getState($this->record()));
    }

    public function test_date_pickers_default_their_calendar_locale_to_the_app_locale(): void
    {
        app()->setLocale('es_MX');

        $data = DatePicker::make('published_at')->toData('create', null);

        // Laravel's es_MX becomes the BCP-47 es-MX for the Reka calendar.
        $this->assertSame('es-MX', $data->dateLocale);

        // An explicit ->locale() still wins.
        $explicit = DatePicker::make('published_at')->locale('fr')->toData('create', null);
        $this->assertSame('fr', $explicit->dateLocale);
    }

    public function test_date_filters_default_their_calendar_locale_to_the_app_locale(): void
    {
        app()->setLocale('pt');

        $data = DateFilter::make('published_at')->toData();

        $this->assertSame('pt', $data->locale);
    }
}
