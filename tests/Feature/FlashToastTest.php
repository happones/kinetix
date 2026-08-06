<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;

class FlashToastTest extends TestCase
{
    /**
     * @return array<string, mixed>|null
     */
    private function sharedToast(): ?array
    {
        $shared = Inertia::getShared();
        $toast  = $shared['kinetix_toast'] ?? null;

        return $toast instanceof \Closure ? $toast() : $toast;
    }

    public function test_a_string_flash_normalizes_to_a_success_toast_with_a_uuid(): void
    {
        Session::flash('kinetix_toast', 'Record created successfully.');

        $toast = $this->sharedToast();

        $this->assertSame('success', $toast['type']);
        $this->assertSame('Record created successfully.', $toast['message']);
        $this->assertTrue(strlen((string) $toast['id']) > 30);
    }

    public function test_an_array_flash_keeps_its_type(): void
    {
        Session::flash('kinetix_toast', ['type' => 'error', 'message' => 'Nope.']);

        $toast = $this->sharedToast();

        $this->assertSame('error', $toast['type']);
        $this->assertSame('Nope.', $toast['message']);
    }

    public function test_an_unknown_type_falls_back_to_success_and_garbage_is_dropped(): void
    {
        Session::flash('kinetix_toast', ['type' => 'sparkles', 'message' => 'Hi.']);
        $this->assertSame('success', $this->sharedToast()['type']);

        Session::flash('kinetix_toast', ['no-message' => true]);
        $this->assertNull($this->sharedToast());
    }

    public function test_no_flash_shares_null(): void
    {
        $this->assertNull($this->sharedToast());
    }

    public function test_two_identical_flashes_get_distinct_ids(): void
    {
        Session::flash('kinetix_toast', 'Saved.');
        $first = $this->sharedToast()['id'];

        Session::flash('kinetix_toast', 'Saved.');
        $second = $this->sharedToast()['id'];

        $this->assertNotSame($first, $second);
    }

    public function test_a_redirect_with_kinetix_toast_flashes_it_into_the_session(): void
    {
        Route::middleware('web')->post('/toast-test', function () {
            return back()->with('kinetix_toast', (string) __('kinetix.record_updated'));
        });

        $this->from('/somewhere')
            ->post('/toast-test')
            ->assertRedirect('/somewhere')
            ->assertSessionHas('kinetix_toast', (string) __('kinetix.record_updated'));
    }
}
