<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;

class ViewColumn extends Column
{
    protected string $view;

    protected array|Closure|null $viewProps = null;

    protected function getType(): string
    {
        return 'view';
    }

    public function view(string $view): static
    {
        $this->view = $view;

        return $this;
    }

    public function props(array|Closure $props): static
    {
        $this->viewProps = $props;

        return $this;
    }

    public function getView(): string
    {
        return $this->view;
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewProps(Model $record): array
    {
        if ($this->viewProps instanceof Closure) {
            return ($this->viewProps)($record);
        }

        return $this->viewProps ?? [];
    }

    protected function getExtraData(): array
    {
        return [
            'view' => $this->view,
        ];
    }
}
