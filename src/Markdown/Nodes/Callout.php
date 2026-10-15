<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown\Nodes;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A GitHub-style alert (`> [!TIP]`), lifted out of its blockquote.
 */
class Callout extends AbstractBlock
{
    public function __construct(public readonly string $type, public readonly string $title)
    {
        parent::__construct();
    }
}
