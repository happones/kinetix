<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Pdf\Contracts\ProvidesPdfData;
use Happones\Kinetix\Pdf\KinetixPdf;
use Happones\Kinetix\Pdf\PdfField;
use Happones\Kinetix\Pdf\PdfTemplate;
use Happones\Kinetix\Pdf\PdfTemplateSetting;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class QuotePdf extends PdfTemplate
{
    public static string $key = 'quote';

    public function logo(): ?string
    {
        return 'data:image/png;base64,iVBORw0KGgo=';
    }
}

class ContractPdf extends PdfTemplate
{
    public static string $key = 'contract';

    public function fields(): array
    {
        return [
            PdfField::color('accent')->default('#10b981'),
            PdfField::toggle('signature')->default(true),
            PdfField::number('copies')->default(1),
        ];
    }
}

class PdfUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class PdfTemplatesTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.pdf.enabled', true);
        $app['config']->set('auth.providers.users.model', PdfUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000019_create_kinetix_pdf_templates_table.php';
        $migration->up();

        KinetixPdf::register(QuotePdf::class);
        KinetixPdf::register(ContractPdf::class);

        Gate::define('viewKinetixPdf', fn ($user = null): bool => true);
    }

    private function user(): PdfUser
    {
        return PdfUser::create(['name' => 'Ada']);
    }

    public function test_show_returns_fields_defaults_and_settings(): void
    {
        $response = $this->actingAs($this->user())
            ->getJson('/_kinetix/pdf-templates/quote')
            ->assertOk();

        $this->assertSame('quote', $response->json('key'));
        $this->assertSame('#6366f1', $response->json('defaults.accent'));
        $this->assertTrue($response->json('hasLogo'));

        $fieldNames = array_column($response->json('fields'), 'name');
        $this->assertContains('accent', $fieldNames);
        $this->assertContains('doc_title', $fieldNames);
    }

    public function test_update_persists_only_declared_fields_with_native_types(): void
    {
        $this->actingAs($this->user())
            ->patchJson('/_kinetix/pdf-templates/contract', [
                'accent'    => '#ef4444',
                'signature' => '0',
                'copies'    => '3',
                'evil'      => 'ignored',
            ])
            ->assertOk();

        $stored = PdfTemplateSetting::for('contract');
        $this->assertSame('#ef4444', $stored['accent']);
        $this->assertFalse($stored['signature']);
        $this->assertSame(3, $stored['copies']);
        $this->assertArrayNotHasKey('evil', $stored);

        // settings() merges the stored values over the defaults.
        $this->assertSame('#ef4444', ContractPdf::make()->settings()['accent']);
    }

    public function test_preview_renders_html_honoring_unsaved_query_overrides(): void
    {
        $response = $this->actingAs($this->user())
            ->get('/_kinetix/pdf-templates/quote/preview?accent=%23ff0000&doc_title=Proposal&striped=0')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=utf-8');

        $html = $response->getContent();
        $this->assertStringContainsString('#ff0000', $html);
        $this->assertStringContainsString('Proposal', $html);
        // Sample data made it into the document.
        $this->assertStringContainsString('Product A', $html);
    }

    public function test_download_returns_a_pdf_via_the_dompdf_driver(): void
    {
        $response = $this->actingAs($this->user())
            ->get('/_kinetix/pdf-templates/quote/download')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_unknown_template_is_404_and_the_gate_is_enforced(): void
    {
        $this->actingAs($this->user())
            ->getJson('/_kinetix/pdf-templates/nope')
            ->assertNotFound();

        Gate::define('viewKinetixPdf', fn ($user = null): bool => false);
        $this->actingAs($this->user())
            ->getJson('/_kinetix/pdf-templates/quote')
            ->assertForbidden();
    }

    public function test_the_facade_renders_with_real_data(): void
    {
        $html = KinetixPdf::render('quote', [
            'number' => 'Q-2049',
            'items'  => [['name' => 'Custom line', 'qty' => 1, 'price' => '10', 'total' => '10']],
        ]);

        $this->assertStringContainsString('Q-2049', $html);
        $this->assertStringContainsString('Custom line', $html);
    }

    public function test_models_providing_pdf_data_are_accepted_directly(): void
    {
        // Implementing the interface…
        $contracted = new class implements ProvidesPdfData
        {
            public function toPdfData(): array
            {
                return ['number' => 'Q-3001', 'items' => []];
            }
        };
        $this->assertStringContainsString('Q-3001', KinetixPdf::render('quote', $contracted));

        // …or just exposing the method (hybrid detection, Filament-style).
        $duckTyped = new class
        {
            public function toPdfData(): array
            {
                return ['number' => 'Q-3002', 'items' => []];
            }
        };
        $this->assertStringContainsString('Q-3002', KinetixPdf::render('quote', $duckTyped));
    }

    public function test_objects_without_pdf_data_throw_a_clear_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('toPdfData');

        KinetixPdf::render('quote', new \stdClass);
    }
}
