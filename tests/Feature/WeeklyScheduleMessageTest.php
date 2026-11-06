<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\Translator;
use ReflectionProperty;

/**
 * The `kinetix_weekly_schedule` message is translated when a value fails, in
 * that request's locale. Translating it at boot loaded the whole `kinetix`
 * lang file on every request and froze the message in the boot locale.
 */
class WeeklyScheduleMessageTest extends TestCase
{
    private function message(array $customMessages = []): string
    {
        $validator = Validator::make(
            ['hours' => 'not a schedule'],
            ['hours' => 'kinetix_weekly_schedule'],
            $customMessages,
            ['hours' => 'opening hours'],
        );

        $this->assertTrue($validator->fails());

        return (string) $validator->errors()->first('hours');
    }

    public function test_the_message_follows_the_locale_of_the_failing_request(): void
    {
        $this->assertSame('The opening hours is not a valid weekly schedule.', $this->message());

        app()->setLocale('es');

        $this->assertSame('El campo opening hours no es un horario semanal válido.', $this->message());
    }

    public function test_the_apps_own_validation_line_wins(): void
    {
        app('translator')->addLines(['validation.kinetix_weekly_schedule' => 'Check the :attribute.'], 'en');

        $this->assertSame('Check the opening hours.', $this->message());
    }

    public function test_an_inline_custom_message_wins(): void
    {
        $this->assertSame('Bad hours', $this->message(['hours.kinetix_weekly_schedule' => 'Bad hours']));
    }

    public function test_booting_does_not_load_the_kinetix_translations(): void
    {
        /** @var Translator $translator */
        $translator = app('translator');
        $loaded     = (array) (new ReflectionProperty($translator, 'loaded'))->getValue($translator);

        // Neither the app's published copy nor the package's own.
        $this->assertArrayNotHasKey('en', (array) ($loaded['*']['kinetix'] ?? []));
        $this->assertArrayNotHasKey('en', (array) ($loaded['kinetix']['kinetix'] ?? []));

        $this->message();

        $loaded = (array) (new ReflectionProperty($translator, 'loaded'))->getValue($translator);
        $this->assertArrayHasKey('en', (array) ($loaded['kinetix']['kinetix'] ?? []));
    }
}
