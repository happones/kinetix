<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tours;

/**
 * One product-tour step: the element to spotlight (CSS selector) and its
 * popover content. `side`/`align` map straight to driver.js's positioning.
 *
 *     TourStep::make('[data-action=create]')
 *         ->titleKey('tours.posts.create_title')
 *         ->descriptionKey('tours.posts.create_body')
 *         ->side('bottom');
 *
 * Tours are declared in a service provider's boot(), before the locale
 * middleware runs, so `__()` there translates into the APP's language. Give
 * translation keys (titleKey()/descriptionKey()) and they are translated per
 * request, in the viewer's language.
 */
class TourStep
{
    protected ?string $title = null;

    protected ?string $description = null;

    /**
     * @var array{0: string, 1: array<string, mixed>}|null
     */
    protected ?array $titleKey = null;

    /**
     * @var array{0: string, 1: array<string, mixed>}|null
     */
    protected ?array $descriptionKey = null;

    protected ?string $side = null;

    /**
     * What the tour opens before highlighting this step: the tab or wizard
     * step holding a field, or the tab itself.
     *
     * @var array{field?: string, tab?: string}|null
     */
    protected ?array $reveal = null;

    protected bool $optional = false;

    protected ?string $align = null;

    final protected function __construct(public readonly string $selector) {}

    public static function make(string $selector): static
    {
        return new static($selector);
    }

    /**
     * The button of the action with this name — table toolbar, row, bulk
     * bar, page header or footer — through the `data-action` hook Kinetix
     * renders on every action.
     */
    public static function action(string $name): static
    {
        return static::make(static::attribute('data-action', $name));
    }

    /**
     * A form field, by name. The tour opens the tab — or wizard step — that
     * holds it first; a wizard never skips a step whose required fields are
     * empty, so then the step's indicator is highlighted instead.
     */
    public static function field(string $name): static
    {
        $step         = static::make(static::attribute('data-kinetix-field', $name));
        $step->reveal = ['field' => $name];

        return $step;
    }

    /**
     * A tab of a form or an infolist, by key (`Tab::key()`, else the slug of
     * its label). The tour opens it, nested tabs included.
     */
    public static function tab(string $key): static
    {
        $step         = static::make(static::attribute('data-kinetix-tab', $key));
        $step->reveal = ['tab' => $key];

        return $step;
    }

    /**
     * A form wizard's step in its indicator, by key (`Step::key()`, else the
     * slug of its label).
     */
    public static function wizardStep(string $key): static
    {
        return static::make(static::attribute('data-kinetix-wizard-step', $key));
    }

    /**
     * A table's header: its heading, search, filters and toolbar actions.
     */
    public static function tableHeader(): static
    {
        return static::make('[data-kinetix-table-header]');
    }

    /**
     * Skip this step when its target isn't on the page (an action the
     * viewer can't see, a column toggled off) instead of showing it centered.
     */
    public function optional(bool $optional = true): static
    {
        $this->optional = $optional;

        return $this;
    }

    protected static function attribute(string $name, string $value): string
    {
        return '['.$name.'="'.addcslashes($value, '"\\').'"]';
    }

    public function title(string $title): static
    {
        $this->title    = $title;
        $this->titleKey = null;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description    = $description;
        $this->descriptionKey = null;

        return $this;
    }

    /**
     * The title as a translation key, translated when the tour is sent — in
     * the viewer's language, not the one the app booted in.
     *
     * @param array<string, mixed> $replace
     */
    public function titleKey(string $key, array $replace = []): static
    {
        $this->titleKey = [$key, $replace];
        $this->title    = null;

        return $this;
    }

    /**
     * The description as a translation key (see {@see titleKey()}).
     *
     * @param array<string, mixed> $replace
     */
    public function descriptionKey(string $key, array $replace = []): static
    {
        $this->descriptionKey = [$key, $replace];
        $this->description    = null;

        return $this;
    }

    /**
     * Preferred popover side: 'top' | 'right' | 'bottom' | 'left'.
     */
    public function side(string $side): static
    {
        $this->side = $side;

        return $this;
    }

    /**
     * Popover alignment on that side: 'start' | 'center' | 'end'.
     */
    public function align(string $align): static
    {
        $this->align = $align;

        return $this;
    }

    /**
     * @return array{selector: string, title: ?string, description: ?string, side: ?string, align: ?string, reveal: array{field?: string, tab?: string}|null, optional: bool}
     */
    public function toArray(): array
    {
        return [
            'selector'    => $this->selector,
            'title'       => $this->titleKey       !== null ? (string) __(...$this->titleKey) : $this->title,
            'description' => $this->descriptionKey !== null ? (string) __(...$this->descriptionKey) : $this->description,
            'side'        => $this->side,
            'align'       => $this->align,
            'reveal'      => $this->reveal,
            'optional'    => $this->optional,
        ];
    }
}
