<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flash;

use Illuminate\Support\Str;

/**
 * An alert being composed by `KinetixFlash::alert()`. It is sent once — on
 * `->send()`, or when the builder goes out of scope, the way a pending job
 * dispatch is — so a fluent chain needs no terminator.
 *
 * By default it shows on the next page only, over Inertia's flash. `keep()`
 * and `untilDismissed()` move it to the session for longer.
 */
class PendingFlashAlert
{
    protected ?string $description = null;

    protected string $variant = 'soft';

    protected ?string $icon = null;

    protected bool $dismissible = true;

    protected ?string $id = null;

    /** Page visits it shows on; null = until the user closes it. */
    protected ?int $showings = 1;

    protected bool $sent = false;

    public function __construct(protected string $title, protected string $color = 'info')
    {
        $this->color($color);
    }

    public function description(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * `success` · `danger` · `warning` · `info` · `primary` · `gray`; anything
     * else reads as `info`.
     */
    public function color(string $color): static
    {
        $this->color = in_array($color, KinetixFlash::ALERT_COLORS, true) ? $color : 'info';

        return $this;
    }

    public function success(): static
    {
        return $this->color('success');
    }

    public function danger(): static
    {
        return $this->color('danger');
    }

    public function warning(): static
    {
        return $this->color('warning');
    }

    public function info(): static
    {
        return $this->color('info');
    }

    /** `soft` (default) · `outline` · `accent`. */
    public function variant(string $variant): static
    {
        $this->variant = in_array($variant, KinetixFlash::ALERT_VARIANTS, true) ? $variant : 'soft';

        return $this;
    }

    /** Any icon name the frontend resolves; null = the color's own icon. */
    public function icon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function dismissible(bool $dismissible = true): static
    {
        $this->dismissible = $dismissible;

        return $this;
    }

    /**
     * A stable id. Sending the same id again replaces the alert instead of
     * stacking a copy, and an id the user closed stays closed for the session.
     */
    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Show it on the next page AND on `$visits` more page visits after it.
     */
    public function keep(int $visits = 1): static
    {
        $this->showings = 1 + max(0, $visits);

        return $this;
    }

    /**
     * Show it on every page until the user closes it (or the app calls
     * `KinetixFlash::forget($id)`), for the rest of the session.
     */
    public function untilDismissed(): static
    {
        $this->showings = null;

        return $this;
    }

    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        $this->sent = true;

        $persistent = $this->showings !== 1;

        $alert = [
            'id'          => $this->id ?? (string) Str::uuid(),
            'title'       => $this->title,
            'description' => $this->description,
            'color'       => $this->color,
            'variant'     => $this->variant,
            'icon'        => $this->icon,
            'dismissible' => $this->dismissible,
            'persistent'  => $persistent,
        ];

        if ($persistent) {
            KinetixFlash::remember($alert, $this->showings);

            return;
        }

        KinetixFlash::push('alerts', $alert);
    }

    public function __destruct()
    {
        $this->send();
    }
}
