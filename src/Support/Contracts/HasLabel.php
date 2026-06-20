<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Contracts;

interface HasLabel
{
    public function getLabel(): ?string;
}
