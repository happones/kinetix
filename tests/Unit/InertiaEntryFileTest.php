<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Support\InertiaEntryFile;
use PHPUnit\Framework\TestCase;

/**
 * The scanner that wraps `createInertiaApp({ resolve: … })` for
 * `kinetix:install --lazy-locales`: it must find the one `resolve:` of the
 * first call's object and never corrupt the file — anything ambiguous is null.
 */
class InertiaEntryFileTest extends TestCase
{
    public function test_it_wraps_a_simple_arrow_resolver(): void
    {
        $source = <<<'JS'
createInertiaApp({
    resolve: (name) => name,
    setup() {},
});
JS;

        $this->assertSame(<<<'JS'
createInertiaApp({
    resolve: w((name) => name),
    setup() {},
});
JS, InertiaEntryFile::wrapResolve($source, 'w'));
    }

    public function test_it_wraps_a_multi_line_value_with_nested_parens_and_a_typescript_generic(): void
    {
        $source = <<<'JS'
void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent<DefineComponent>(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>(['./pages/**/*.vue', '!./pages/auth/**']),
        ),
    layout: (name) => (name.startsWith('auth/') ? AuthLayout : AppLayout),
    setup({ el, App, props, plugin }) {},
});
JS;

        $wrapped = InertiaEntryFile::wrapResolve($source, 'withKinetixLocales');

        $this->assertNotNull($wrapped);
        $this->assertStringContainsString(<<<'JS'
    resolve: withKinetixLocales((name) =>
        resolvePageComponent<DefineComponent>(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>(['./pages/**/*.vue', '!./pages/auth/**']),
        )),
    layout:
JS, $wrapped);
    }

    public function test_template_literals_with_commas_and_braces_do_not_end_the_value(): void
    {
        $source = "createInertiaApp({\n    resolve: (name) => load(`./pages/\${name.replace(/}/g, '')}, x}.vue`),\n    setup() {},\n});";

        $wrapped = InertiaEntryFile::wrapResolve($source, 'w');

        $this->assertSame(
            "createInertiaApp({\n    resolve: w((name) => load(`./pages/\${name.replace(/}/g, '')}, x}.vue`)),\n    setup() {},\n});",
            $wrapped,
        );
    }

    public function test_comments_are_skipped_and_a_trailing_line_comment_stays_outside_the_wrap(): void
    {
        $source = <<<'JS'
// resolve: nope,
createInertiaApp({
    /* resolve: also nope, */
    resolve: (name) => pages[name] // the pages map
    ,
    setup() {},
});
JS;

        $wrapped = InertiaEntryFile::wrapResolve($source, 'w');

        $this->assertNotNull($wrapped);
        $this->assertStringContainsString('resolve: w((name) => pages[name]) // the pages map', $wrapped);
        $this->assertStringContainsString('// resolve: nope,', $wrapped);
        $this->assertStringContainsString('/* resolve: also nope, */', $wrapped);
    }

    public function test_a_resolve_key_inside_a_nested_object_is_ignored(): void
    {
        $source = <<<'JS'
createInertiaApp({
    progress: { resolve: 'nested', color: '#000' },
    resolve: (name) => name,
});
JS;

        $wrapped = InertiaEntryFile::wrapResolve($source, 'w');

        $this->assertStringContainsString("progress: { resolve: 'nested', color: '#000' },", (string) $wrapped);
        $this->assertStringContainsString('resolve: w((name) => name),', (string) $wrapped);
    }

    public function test_the_last_property_without_a_trailing_comma_is_wrapped(): void
    {
        $this->assertSame(
            'createInertiaApp({ setup() {}, resolve: x((n) => n) })',
            InertiaEntryFile::wrapResolve('createInertiaApp({ setup() {}, resolve: (n) => n })', 'x'),
        );
    }

    public function test_two_resolve_keys_are_ambiguous(): void
    {
        $source = <<<'JS'
createInertiaApp({
    resolve: (name) => a(name),
    resolve: (name) => b(name),
});
JS;

        $this->assertNull(InertiaEntryFile::wrapResolve($source, 'w'));
    }

    public function test_no_resolve_key_or_a_method_shorthand_is_null(): void
    {
        $this->assertNull(InertiaEntryFile::wrapResolve("createInertiaApp({\n    setup() {},\n});", 'w'));
        $this->assertNull(InertiaEntryFile::wrapResolve("createInertiaApp({\n    resolve(name) { return name; },\n});", 'w'));
        $this->assertNull(InertiaEntryFile::wrapResolve("createInertiaApp({\n    resolve,\n});", 'w'));
    }

    public function test_only_the_first_create_inertia_app_call_counts(): void
    {
        $source = "createInertiaApp({ resolve: a });\ncreateInertiaApp({ resolve: b });";

        $this->assertSame(
            "createInertiaApp({ resolve: w(a) });\ncreateInertiaApp({ resolve: b });",
            InertiaEntryFile::wrapResolve($source, 'w'),
        );
    }

    public function test_unterminated_constructs_and_a_missing_call_are_null(): void
    {
        $this->assertNull(InertiaEntryFile::wrapResolve("createInertiaApp({\n    resolve: (name) => `oops,\n", 'w'));
        $this->assertNull(InertiaEntryFile::wrapResolve("createInertiaApp({\n    resolve: (name) => name,\n", 'w'));
        $this->assertNull(InertiaEntryFile::wrapResolve("/* never closed\ncreateInertiaApp({ resolve: a });", 'w'));
        $this->assertNull(InertiaEntryFile::wrapResolve('mount({ resolve: a });', 'w'));
        $this->assertNull(InertiaEntryFile::wrapResolve('createInertiaApp({ resolve: , setup() {} });', 'w'));
    }

    public function test_strings_holding_the_call_or_the_key_are_skipped(): void
    {
        $source = "const s = 'createInertiaApp({ resolve: x })';\ncreateInertiaApp({\n    'resolve': (name) => name,\n});";

        $this->assertSame(
            "const s = 'createInertiaApp({ resolve: x })';\ncreateInertiaApp({\n    'resolve': w((name) => name),\n});",
            InertiaEntryFile::wrapResolve($source, 'w'),
        );
    }
}
