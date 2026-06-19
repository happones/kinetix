<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

class CustomWidget extends Widget
{
    protected string $type = 'custom';

    protected array $properties = [];

    public function properties(array $properties): static
    {
        $this->properties = $properties;

        return $this;
    }

    protected function getData(): array
    {
        return $this->properties;
    }
}
