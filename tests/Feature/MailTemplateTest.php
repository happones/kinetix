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
