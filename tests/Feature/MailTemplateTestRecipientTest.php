<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Mail\MailTemplate;
use Happones\Kinetix\Mail\TemplatedMail;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class MtrUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The mail-template test send must not be a way to mail strangers: by
 * default it only goes to the acting user's own address, and it is
 * throttled.
 */
class MailTemplateTestRecipientTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.mail_templates.enabled', true);
        $app['config']->set('auth.providers.users.model', MtrUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewKinetixMail', fn () => true);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
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

    public function test_a_test_email_to_someone_else_is_refused_and_nothing_is_sent(): void
    {
        Mail::fake();

        $this->actingAs(MtrUser::create(['email' => 'me@acme.com']))
            ->postJson("/_kinetix/mail-templates/{$this->template()->id}/test", ['email' => 'victim@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('kinetix.mail_test_self_only'));

        Mail::assertNothingSent();
    }

    public function test_without_an_address_it_goes_to_the_acting_user(): void
    {
        Mail::fake();

        $this->actingAs(MtrUser::create(['email' => 'me@acme.com']))
            ->postJson("/_kinetix/mail-templates/{$this->template()->id}/test")
            ->assertOk();

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('me@acme.com'));
    }

    public function test_the_users_own_address_is_accepted_in_any_case(): void
    {
        Mail::fake();

        $this->actingAs(MtrUser::create(['email' => 'me@acme.com']))
            ->postJson("/_kinetix/mail-templates/{$this->template()->id}/test", ['email' => 'Me@Acme.com'])
            ->assertOk();

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('me@acme.com'));
    }

    public function test_a_user_without_an_address_gets_a_422(): void
    {
        Mail::fake();

        $this->actingAs(MtrUser::create([]))
            ->postJson("/_kinetix/mail-templates/{$this->template()->id}/test")
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_any_mode_sends_to_the_given_address(): void
    {
        config()->set('kinetix.mail_templates.test_recipients', 'any');
        Mail::fake();

        $this->actingAs(MtrUser::create(['email' => 'me@acme.com']))
            ->postJson("/_kinetix/mail-templates/{$this->template()->id}/test", ['email' => 'qa@acme.com'])
            ->assertOk();

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('qa@acme.com'));
    }

    public function test_the_index_says_where_test_emails_go(): void
    {
        $this->actingAs(MtrUser::create(['email' => 'me@acme.com']))
            ->getJson(route('kinetix.mail-templates.index'))
            ->assertOk()
            ->assertJsonPath('test.recipients', 'self')
            ->assertJsonPath('test.email', 'me@acme.com');

        config()->set('kinetix.mail_templates.test_recipients', 'any');

        $this->actingAs(MtrUser::create([]))
            ->getJson(route('kinetix.mail-templates.index'))
            ->assertJsonPath('test.recipients', 'any')
            ->assertJsonPath('test.email', null);
    }

    public function test_test_emails_are_throttled(): void
    {
        Mail::fake();
        $user     = MtrUser::create(['email' => 'me@acme.com']);
        $template = $this->template();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)->postJson("/_kinetix/mail-templates/{$template->id}/test")->assertOk();
        }

        $this->actingAs($user)->postJson("/_kinetix/mail-templates/{$template->id}/test")
            ->assertStatus(429)
            ->assertJsonPath('code', 'throttled');

        Mail::assertSentCount(5);
    }

    private function template(): MailTemplate
    {
        return MailTemplate::query()->firstOrCreate(['key' => 'welcome'], [
            'name'      => 'Welcome',
            'subject'   => 'Welcome, {{ name }}!',
            'body'      => 'Hi {{ name }}',
            'format'    => 'markdown',
            'variables' => [['key' => 'name', 'sample' => 'Ada']],
        ]);
    }
}
