<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\Finder\Finder;

/**
 * Laravel hands a controller its route parameters by POSITION. Under the team
 * prefix every Kinetix route gains a leading `{current_team}`, so a method
 * declaring `string $slug` receives the team, not the slug: a comment edit
 * 404s, a wizard completes under the team's name. Every Kinetix controller
 * reads its route parameters by name (`$request->route('slug')`) instead.
 */
class ControllerRouteParametersTest extends TestCase
{
    public function test_no_controller_takes_a_route_parameter_by_position(): void
    {
        $offenders = [];

        foreach ((new Finder)->files()->in(__DIR__.'/../../src')->name('*Controller.php') as $file) {
            $class = 'Happones\\Kinetix\\'.Str::of($file->getRelativePathname())->beforeLast('.php')->replace('/', '\\');

            if (! class_exists($class)) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor() || $method->isStatic()) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    if ($this->takesAValue($parameter->getType())) {
                        $offenders[] = "{$class}::{$method->getName()}(\${$parameter->getName()})";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Read these by name, $request->route(...), not positionally.');
    }

    private function takesAValue(mixed $type): bool
    {
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        foreach ($types as $named) {
            if ($named instanceof ReflectionNamedType && $named->isBuiltin()) {
                return true;
            }
        }

        return false;
    }
}
