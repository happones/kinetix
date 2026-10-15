<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown\Nodes;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * Wraps a table so a wide one scrolls on its own instead of widening a phone.
 */
class TableScroll extends AbstractBlock {}
