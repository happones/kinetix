<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

use Closure;
use Happones\Kinetix\Data\WidgetData;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Gate;
use JsonSerializable;

abstract class Widget implements Arrayable, JsonSerializable
{
    protected string $id;

    protected string $type;

    protected ?string $title = null;

    protected ?string $description = null;

    protected int|string|array $columnSpan = 12;

    protected ?int $sort = 0;

    /**
     * Link/button actions shown in the widget header.
     *
     * @var array<int, array{label: string, url: string, icon: string|null}>
     */
    protected array $headerActions = [];

    protected bool|Closure $isVisible = true;

    protected bool|Closure $isHidden = false;

    protected string|Closure|bool|null $authorizeUsing = null;

    protected mixed $authorizeArguments = null;

    public function __construct()
    {
        $this->id = uniqid('widget_', true);
    }

    public static function make(): static
    {
        return new static;
    }

    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function columnSpan(int|string|array $columnSpan): static
    {
        $this->columnSpan = $columnSpan;

        return $this;
    }

    public function sort(int $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * Add a link/button action to the widget header (e.g. "Export", "View all").
     */
    public function headerAction(string $label, string $url, ?string $icon = null): static
    {
        $this->headerActions[] = ['label' => $label, 'url' => $url, 'icon' => $icon];

        return $this;
    }

    /**
     * Show/hide the widget based on a boolean or a closure (e.g. role checks).
     */
    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    /**
     * Inverse of {@see visible()} — hide the widget when the condition is true.
     */
    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    /**
     * Restrict the widget to users passing a Laravel Gate ability, a boolean,
     * or a closure. A widget has no natural "record" to authorize against —
     * pass `$arguments` for abilities that take a subject
     * (`->authorize('view', $team)`), or omit it for a bare ability
     * (`->authorize('viewFinancials')`, i.e. `Gate::allows('viewFinancials')`).
     *
     *     StatsOverviewWidget::make(...)->authorize('viewFinancials');
     *     ChartWidget::make(...)->visible(fn () => auth()->user()->hasRole('admin'));
     */
    public function authorize(string|Closure|bool $ability, mixed $arguments = null): static
    {
        $this->authorizeUsing     = $ability;
        $this->authorizeArguments = $arguments;

        return $this;
    }

    /**
     * Whether this widget passes both visibility and authorization — checked
     * by {@see WidgetsGrid::toArray()} before the widget is serialized (or its
     * `getData()` even runs), so a user who fails either check never receives
     * the widget's payload at all.
     */
    public function shouldRender(): bool
    {
        return $this->passesVisibility() && $this->passesAuthorization();
    }

    protected function passesVisibility(): bool
    {
        if ($this->isHidden instanceof Closure ? ($this->isHidden)() : $this->isHidden) {
            return false;
        }

        return $this->isVisible instanceof Closure ? (bool) ($this->isVisible)() : (bool) $this->isVisible;
    }

    protected function passesAuthorization(): bool
    {
        if ($this->authorizeUsing === null) {
            return true;
        }

        if (is_bool($this->authorizeUsing)) {
            return $this->authorizeUsing;
        }

        if ($this->authorizeUsing instanceof Closure) {
            return (bool) ($this->authorizeUsing)();
        }

        return $this->authorizeArguments !== null
            ? Gate::allows($this->authorizeUsing, $this->authorizeArguments)
            : Gate::allows($this->authorizeUsing);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSort(): int
    {
        return $this->sort ?? 0;
    }

    /**
     * The widget type's own payload (series, values, rows).
     *
     * @return array<string, mixed>
     */
    abstract protected function getData(): array;

    /**
     * Serialize through a Data class like every other Kinetix builder, so the
     * widget envelope gets a generated TypeScript contract instead of being an
     * untyped array the frontend has to guess at.
     */
    public function toData(): WidgetData
    {
        return new WidgetData(
            id: $this->id,
            type: $this->type,
            title: $this->title,
            description: $this->description,
            columnSpan: $this->columnSpan,
            sort: $this->sort,
            headerActions: $this->headerActions,
            data: $this->getData(),
        );
    }

    public function toArray(): array
    {
        return $this->toData()->toArray();
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
