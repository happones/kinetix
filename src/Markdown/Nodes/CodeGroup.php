<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown\Nodes;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * Adjacent titled code blocks (```bash [npm] / ```bash [yarn]) shown as tabs.
 */
class CodeGroup extends AbstractBlock
{
    public function __construct(public readonly string $id)
    {
        parent::__construct();
    }
}
