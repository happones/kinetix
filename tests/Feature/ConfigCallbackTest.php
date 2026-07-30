<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Tests\TestCase;

class CallbackTarget
{
    public function __construct(public string $injected = 'from-container') {}

    public function instanceMethod(string $value): string
    {
        return $this->injected.':'.$value;
    }

    public static function staticMethod(string $value): string
    {
        return 'static:'.$value;
    }

    public function __invoke(string $value): string
    {
        return 'invoked:'.$value;
    }
}

class ConfigCallbackTest extends TestCase
{
    public function test_a_callable_array_with_an_instance_method_is_container_resolved(): void
    {
        $callback = ConfigCallback::resolve([CallbackTarget::class, 'instanceMethod']);

        $this->assertNotNull($callback);
        $this->assertSame('from-container:x', $callback('x'));
    }

    public function test_a_callable_array_with_a_static_method_is_called_directly(): void
    {
        $callback = ConfigCallback::resolve([CallbackTarget::class, 'staticMethod']);

        $this->assertNotNull($callback);
        $this->assertSame('static:x', $callback('x'));
    }

    public function test_an_object_method_pair_is_accepted(): void
    {
        $callback = ConfigCallback::resolve([new CallbackTarget('local'), 'instanceMethod']);

        $this->assertNotNull($callback);
        $this->assertSame('local:x', $callback('x'));
    }

    public function test_an_invokable_class_string_is_accepted(): void
    {
        $callback = ConfigCallback::resolve(CallbackTarget::class);

        $this->assertNotNull($callback);
        $this->assertSame('invoked:x', $callback('x'));
    }

    public function test_a_closure_is_returned_as_is(): void
    {
        $closure = static fn (string $value): string => 'closure:'.$value;

        $this->assertSame('closure:x', ConfigCallback::resolve($closure)('x'));
    }

    /**
     * The whole point of these forms: they survive `config:cache`, which writes
     * `<?php return var_export($config, true);` and requires it back. A closure
     * cannot make that round trip, which is why a closure-based config makes the
     * app undeployable.
     */
    public function test_the_supported_forms_survive_the_config_cache_round_trip(): void
    {
        $forms = [
            'callable array'         => [CallbackTarget::class, 'instanceMethod'],
            'static callable array'  => [CallbackTarget::class, 'staticMethod'],
            'invokable class-string' => CallbackTarget::class,
        ];

        $path = sys_get_temp_dir().'/kinetix_config_cache_'.uniqid().'.php';

        try {
            file_put_contents($path, '<?php return '.var_export($forms, true).';');

            /** @var array<string, mixed> $restored */
            $restored = require $path;

            foreach ($restored as $label => $value) {
                $this->assertNotNull(
                    ConfigCallback::resolve($value),
                    "The {$label} form did not survive the round trip.",
                );
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonCallbackProvider(): array
    {
        return [
            'a list of role names'  => [['editor', 'viewer']],
            'a single string'       => ['editor'],
            'a three-element array' => [['a', 'b', 'c']],
            'an unknown class pair' => [['App\\Nope', 'attach']],
            'a missing method pair' => [[CallbackTarget::class, 'nope']],
            'null'                  => [null],
            'true'                  => [true],
        ];
    }

    public function test_values_that_are_not_callbacks_resolve_to_null(): void
    {
        foreach (static::nonCallbackProvider() as $label => [$value]) {
            $this->assertNull(
                ConfigCallback::resolve($value),
                "Expected {$label} not to be treated as a callback.",
            );
        }
    }
}
