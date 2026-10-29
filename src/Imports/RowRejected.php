<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use RuntimeException;

/**
 * Throw from an importer (or call {@see Importer::reject()}) to turn the
 * current row down: it counts as failed, not imported, and its message is the
 * reason the notification and the failed-rows file give.
 */
class RowRejected extends RuntimeException {}
