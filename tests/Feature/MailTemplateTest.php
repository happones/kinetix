<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Mail\KinetixMail;
use Happones\Kinetix\Mail\MailTemplate;
use Happones\Kinetix\Mail\TemplatedMail;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class MailUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class MailTemplateTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.mail_templates.enabled', true);
        $app['config']->set('auth.providers.users.model', MailUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('kinetix_mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('subject');
            $table->longText('body');
            $table->string('format')->default('markdown');
            $table->json('variables')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    private function template(array $overrides = []): MailTemplate
    {
        return MailTemplate::create(array_merge([
            'key'       => 'welcome',
            'name'      => 'Welcome',
            'subject'   => 'Welcome, {{ name }}!',
            'body'      => "# Hi {{ name }}\n\nYour total is {{ total }}.",
            'format'    => 'markdown',
            'variables' => [['key' => 'name', 'sample' => 'Ada'], ['key' => 'total', 'sample' => '$10']],
        ], $overrides));
    }

    public function test_render_interpolates_variables_and_compiles_markdown(): void
    {
        $rendered = $this->template()->render(['name' => 'Ada', 'total' => '$42']);

        $this->assertSame('Welcome, Ada!', $rendered['subject']);
        $this->assertStringContainsString('<h1>Hi Ada</h1>', $rendered['html']);
        $this->assertStringContainsString('Your total is $42.', $rendered['html']);
    }

    /**
     * A bare list of names left the editor with empty rows and every sample
     * blank: it reads as rows named by those keys, however it was stored.
     */
    public function test_variables_given_as_bare_names_read_as_rows(): void
    {
        $template = $this->template(['variables' => ['name', 'total']]);

        $this->assertSame([
            ['key' => 'name', 'label' => null, 'sample' => null],
            ['key' => 'total', 'label' => null, 'sample' => null],
        ], $template->fresh()?->variables);

        // A row written before this normalization, straight into the column.
        MailTemplate::query()->whereKey($template->getKey())->toBase()->update(['variables' => json_encode(['team'])]);

        $this->assertSame([['key' => 'team', 'label' => null, 'sample' => null]], $template->fresh()?->variables);
        $this->assertSame(['team' => 'team'], $template->fresh()?->sampleData());
    }

    public function test_markdown_body_escapes_variable_values(): void
    {
        $html = $this->template()->render(['name' => '<script>x</script>', 'total' => ''])['html'];

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_html_format_is_left_as_is(): void
    {
        $template = $this->template([
            'format' => 'html',
            'body'   => '<p>Hello {{ name }}</p>',
        ]);

        $this->assertSame('<p>Hello Ada</p>', $template->render(['name' => 'Ada'])['html']);
    }

    public function test_an_html_template_escapes_its_variables(): void
    {
        $template = $this->template(['format' => 'html', 'body' => '<p>Hello {{ name }}</p>']);

        $html = $template->render(['name' => '<script>alert(1)</script>'])['html'];

        $this->assertSame('<p>Hello &lt;script&gt;alert(1)&lt;/script&gt;</p>', $html);
    }

    public function test_raw_placeholders_insert_the_value_as_is(): void
    {
        $template = $this->template(['format' => 'html', 'body' => '<div>{!! banner !!}</div><p>{{ banner }}</p>']);

        $html = $template->render(['banner' => '<b>Sale</b>'])['html'];

        $this->assertSame('<div><b>Sale</b></div><p>&lt;b&gt;Sale&lt;/b&gt;</p>', $html);
    }

    public function test_a_markdown_variable_cannot_inject_markdown(): void
    {
        $html = $this->template(['body' => 'Hello {{ name }}'])
            ->render(['name' => '[click me](javascript:alert(1)) **bold** <img src=x onerror=alert(1)>'])['html'];

        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('[click me](javascript:alert(1))', $html);
        $this->assertStringContainsString('**bold**', $html);
    }

    public function test_a_variable_url_still_works_as_a_markdown_link(): void
    {
        $html = $this->template(['body' => '[Activate]({{ url }})'])->render(['url' => 'https://example.com/a?b=1&c=2'])['html'];

        $this->assertStringContainsString('<a href="https://example.com/a?b=1&amp;c=2">Activate</a>', $html);
    }

    public function test_a_markdown_body_drops_unsafe_links(): void
    {
        $html = $this->template(['body' => '[Pay now](javascript:alert(1)) and [docs](https://example.com)'])->render([])['html'];

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function test_the_subject_stays_plain_text(): void
    {
        $subject = $this->template(['subject' => 'Hi {{ name }}'])->render(['name' => 'Ada & <b>Bob</b>'])['subject'];

        $this->assertSame('Hi Ada & Bob', $subject);
    }

    public function test_kinetix_mail_send_dispatches_the_mailable(): void
    {
        Mail::fake();
        $this->template();

        $this->assertTrue(KinetixMail::send('ops@acme.com', 'welcome', ['name' => 'Ada']));

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('ops@acme.com')
            && $m->subjectLine === 'Welcome, Ada!');
    }

    public function test_send_returns_false_for_unknown_or_disabled_template(): void
    {
        Mail::fake();
        $this->template(['enabled' => false]);

        $this->assertFalse(KinetixMail::send('x@y.com', 'welcome', []));
        $this->assertFalse(KinetixMail::send('x@y.com', 'missing', []));
        Mail::assertNothingSent();
    }

    public function test_preview_endpoint_renders_unsaved_content(): void
    {
        Gate::define('viewKinetixMail', fn () => true);

        $this->actingAs(MailUser::create([]))
            ->postJson('/_kinetix/mail-templates/preview', [
                'subject' => 'Hi {{ name }}',
                'body'    => 'Hello {{ name }}',
                'format'  => 'markdown',
                'data'    => ['name' => 'Ada'],
            ])
            ->assertOk()
            ->assertJsonPath('subject', 'Hi Ada');
    }

    public function test_test_endpoint_sends_with_sample_data(): void
    {
        config()->set('kinetix.mail_templates.test_recipients', 'any');
        Mail::fake();
        Gate::define('viewKinetixMail', fn () => true);
        $template = $this->template();

        $this->actingAs(MailUser::create([]))
            ->postJson("/_kinetix/mail-templates/{$template->id}/test", ['email' => 'qa@acme.com'])
            ->assertOk();

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('qa@acme.com'));
    }

    public function test_crud_endpoints_are_gated(): void
    {
        Gate::define('viewKinetixMail', fn () => false);

        $this->actingAs(MailUser::create([]))
            ->getJson('/_kinetix/mail-templates')
            ->assertForbidden();
    }
}
