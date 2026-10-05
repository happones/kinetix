<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Flash\KinetixFlash;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Support\SessionKey;

/**
 * KinetixFlash: one-shot toasts and alerts over Inertia's flash channel (never
 * stored in the browser history), and alerts that outlive one page in the
 * session (`keep()` / `untilDismissed()`).
 */
class KinetixFlashTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function flashed(): array
    {
        $flash = session(SessionKey::FLASH_DATA, [])[KinetixFlash::FLASH_KEY] ?? [];

        return is_array($flash) ? $flash : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function persistent(?Request $request = null): array
    {
        return KinetixFlash::persistentAlerts($request ?? $this->sessionRequest());
    }

    /**
     * @param array<string, string> $headers
     */
    private function sessionRequest(array $headers = []): Request
    {
        $request = Request::create('/page');
        $request->headers->add($headers);

        return $request;
    }

    public function test_toasts_ride_the_inertia_flash_and_accumulate(): void
    {
        KinetixFlash::success('Invoice sent.');
        KinetixFlash::toast('Import queued', 'info', 'We will email you.', 6000);

        $toasts = $this->flashed()['toasts'];

        $this->assertCount(2, $toasts);
        $this->assertSame('success', $toasts[0]['type']);
        $this->assertSame('Invoice sent.', $toasts[0]['message']);
        $this->assertSame('We will email you.', $toasts[1]['description']);
        $this->assertSame(6000, $toasts[1]['duration']);
        $this->assertNotSame($toasts[0]['id'], $toasts[1]['id']);
    }

    public function test_an_unknown_toast_type_reads_as_success(): void
    {
        KinetixFlash::toast('Hi.', 'sparkles');

        $this->assertSame('success', $this->flashed()['toasts'][0]['type']);
    }

    public function test_an_alert_is_sent_without_a_terminator(): void
    {
        KinetixFlash::alert('Payment failed', 'danger')->description('Card declined.');

        $alert = $this->flashed()['alerts'][0];

        $this->assertSame('Payment failed', $alert['title']);
        $this->assertSame('Card declined.', $alert['description']);
        $this->assertSame('danger', $alert['color']);
        $this->assertFalse($alert['persistent']);
        // One-shot: nothing parked in the session.
        $this->assertSame([], $this->persistent());
    }

    public function test_keep_zero_is_just_the_next_page(): void
    {
        KinetixFlash::alert('Saved')->keep(0);

        $this->assertCount(1, $this->flashed()['alerts']);
        $this->assertSame([], $this->persistent());
    }

    public function test_send_is_idempotent(): void
    {
        $pending = KinetixFlash::alert('Once');
        $pending->send();
        $pending->send();
        unset($pending);

        $this->assertCount(1, $this->flashed()['alerts']);
    }

    public function test_unknown_colors_and_variants_fall_back(): void
    {
        KinetixFlash::alert('Odd', 'chartreuse')->variant('neon');

        $alert = $this->flashed()['alerts'][0];

        $this->assertSame('info', $alert['color']);
        $this->assertSame('soft', $alert['variant']);
    }

    public function test_a_redirect_carries_the_flash_to_the_next_page(): void
    {
        Route::middleware('web')->post('/flash-test', function () {
            KinetixFlash::success('Saved.');

            return back();
        });

        $this->from('/somewhere')
            ->post('/flash-test')
            ->assertRedirect('/somewhere');

        $this->assertSame('Saved.', $this->flashed()['toasts'][0]['message']);
    }

    public function test_keep_shows_the_alert_on_more_visits_then_drops_it(): void
    {
        KinetixFlash::alert('Welcome aboard', 'success')->keep(1);

        $this->assertSame([], $this->flashed());

        $first = $this->persistent();
        $this->assertCount(1, $first);
        $this->assertTrue($first[0]['persistent']);

        $this->assertCount(1, $this->persistent());
        $this->assertSame([], $this->persistent());
    }

    public function test_a_prefetch_does_not_use_a_showing_up(): void
    {
        KinetixFlash::alert('Welcome aboard')->keep(1);

        // Hovering a link prefetches the next page: shown, not counted.
        $this->assertCount(1, $this->persistent($this->sessionRequest(['Purpose' => 'prefetch'])));

        // Still two real visits left.
        $this->assertCount(1, $this->persistent());
        $this->assertCount(1, $this->persistent());
        $this->assertSame([], $this->persistent());
    }

    public function test_until_dismissed_stays_until_the_user_closes_it(): void
    {
        KinetixFlash::alert('Verify your email', 'warning')->id('verify-email')->untilDismissed();

        $this->assertCount(1, $this->persistent());
        $this->assertCount(1, $this->persistent());

        $alert = $this->persistent()[0];
        $this->assertSame('/_kinetix/flash/verify-email/dismiss', $alert['dismissUrl']);

        // Guests too: the endpoint needs the session, not a login.
        $this->postJson($alert['dismissUrl'])->assertOk()->assertJson(['dismissed' => true]);

        $this->assertSame([], $this->persistent());
    }

    public function test_a_closed_stable_id_is_not_brought_back_by_sending_it_again(): void
    {
        KinetixFlash::alert('Verify your email')->id('verify-email')->untilDismissed();
        KinetixFlash::dismiss('verify-email');

        // A middleware re-sending it on every request.
        KinetixFlash::alert('Verify your email')->id('verify-email')->untilDismissed();

        $this->assertSame([], $this->persistent());
    }

    public function test_sending_a_stable_id_again_replaces_instead_of_stacking(): void
    {
        KinetixFlash::alert('Trial ends in 3 days')->id('trial')->untilDismissed();
        KinetixFlash::alert('Trial ends in 2 days')->id('trial')->untilDismissed();

        $alerts = $this->persistent();

        $this->assertCount(1, $alerts);
        $this->assertSame('Trial ends in 2 days', $alerts[0]['title']);
    }

    public function test_forget_withdraws_without_counting_as_closed(): void
    {
        KinetixFlash::alert('Card expiring')->id('card')->untilDismissed();
        KinetixFlash::forget('card');
        $this->assertSame([], $this->persistent());

        // Not marked closed: the app may raise it again later.
        KinetixFlash::alert('Card expiring')->id('card')->untilDismissed();
        $this->assertCount(1, $this->persistent());
    }

    public function test_the_session_alerts_are_shared_as_kinetix_alerts(): void
    {
        KinetixFlash::alert('Maintenance tonight')->untilDismissed();

        $shared = Inertia::getShared('kinetix_alerts');
        $alerts = is_callable($shared) ? $shared() : $shared;

        $this->assertCount(1, $alerts);
        $this->assertSame('Maintenance tonight', $alerts[0]['title']);
    }

    public function test_the_close_endpoint_ignores_the_team_prefix(): void
    {
        $this->assertSame(
            '/_kinetix/flash/abc/dismiss',
            route('kinetix.flash.dismiss', ['id' => 'abc'], false),
        );
    }
}
