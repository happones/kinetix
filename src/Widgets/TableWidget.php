<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

class TableWidget extends Widget
{
    protected string $type = 'table';

    protected array $headers = [];

    protected array $rows = [];

    public function headers(array $headers): static
    {
        $this->headers = $headers;

        return $this;
    }

    public function rows(array $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    protected function getData(): array
    {
        return [
            'headers' => $this->headers,
            'rows'    => $this->rows,
        ];
    }
}
