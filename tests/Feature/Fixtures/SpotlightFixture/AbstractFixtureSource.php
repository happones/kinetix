<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature\Fixtures\SpotlightFixture;

use Happones\Kinetix\Spotlight\SpotlightSource;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An abstract source in the discovered directory — must be excluded (it can't
 * be instantiated).
 */
abstract class AbstractFixtureSource implements SpotlightSource
{
    public function authorizedFor(?Authenticatable $user): bool
    {
        return true;
    }
}
