<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

class DownloadAction extends Action
{
    public static function make(string $name = 'download'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'download')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.download'))
            ->icon('download')
            ->color('gray')
            ->download();
    }
}
