<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Query\SearchTerm;
use Happones\Kinetix\Tests\TestCase;
use Inertia\Inertia;

class SearchTermTest extends TestCase
{
    public function test_the_term_is_trimmed_and_stripped_of_control_characters(): void
    {
        $this->assertSame('ada lovelace', SearchTerm::from("  ada\x00\x07 lovelace \n"));
        $this->assertSame('a b', SearchTerm::from("a\tb"));
    }

    public function test_only_strings_are_terms(): void
    {
        $this->assertSame('', SearchTerm::from(['a']));
        $this->assertSame('', SearchTerm::from(null));
        $this->assertSame('12', SearchTerm::from(12));
        $this->assertSame('1.5', SearchTerm::from(1.5));
        $this->assertSame('', SearchTerm::from(true));
        $this->assertSame('', SearchTerm::from("\xC3\x28"));
    }

    public function test_the_term_is_truncated_by_characters_never_rejected(): void
    {
        $this->assertSame(100, SearchTerm::maxLength());
        $this->assertSame(100, mb_strlen(SearchTerm::from(str_repeat('ñ', 400))));

        config()->set('kinetix.search.max_length', 5);
        $this->assertSame('ñandú', SearchTerm::from('ñandú y más'));
    }

    public function test_a_null_cap_leaves_the_term_uncapped(): void
    {
        config()->set('kinetix.search.max_length', null);

        $this->assertNull(SearchTerm::maxLength());
        $this->assertSame(500, mb_strlen(SearchTerm::from(str_repeat('a', 500))));
    }

    public function test_the_minimum_only_judges_non_empty_terms(): void
    {
        $this->assertSame(1, SearchTerm::minChars());
        $this->assertFalse(SearchTerm::isBelowMinimum('a'));

        config()->set('kinetix.search.min_chars', 3);

        $this->assertTrue(SearchTerm::isBelowMinimum('ab'));
        $this->assertFalse(SearchTerm::isBelowMinimum('abc'));
        $this->assertFalse(SearchTerm::isBelowMinimum(''));

        config()->set('kinetix.search.min_chars', 0);
        $this->assertSame(1, SearchTerm::minChars());
    }

    public function test_the_shared_config_prop_carries_the_limits_without_queries(): void
    {
        config()->set('kinetix.search.min_chars', 2);

        $props = $this->sharedConfig();

        $this->assertSame(
            ['min_chars' => 2, 'max_length' => 100, 'debounce' => 250, 'cache_ttl' => 30, 'cache_entries' => 100],
            $props['search'],
        );

        config()->set('kinetix.search.client', ['debounce' => 400, 'cache_ttl' => 0, 'cache_entries' => 'x']);
        $this->assertSame(
            ['debounce' => 400, 'cache_ttl' => 0, 'cache_entries' => 100],
            SearchTerm::clientSettings(),
        );
        config()->set('kinetix.search.client', null);

        config()->set('kinetix.search.max_length', null);
        $this->assertNull($this->sharedConfig()['search']['max_length']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedConfig(): array
    {
        $share = Inertia::getShared('kinetix_config');

        return value($share);
    }
}
