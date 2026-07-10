<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Tests\TestCase;

class TranslationParityTest extends TestCase
{
    private const LOCALES = ['en', 'es', 'fr', 'pt', 'zh', 'ja', 'ru'];

    /**
     * @return array<string, mixed>
     */
    private function load(string $locale): array
    {
        return require __DIR__."/../../resources/lang/{$locale}/kinetix.php";
    }

    public function test_all_locales_share_the_same_keys(): void
    {
        $en = array_keys($this->load('en'));
        sort($en);

        foreach (array_slice(self::LOCALES, 1) as $locale) {
            $keys = array_keys($this->load($locale));
            sort($keys);

            $this->assertSame(
                $en,
                $keys,
                "Locale [{$locale}] keys differ from [en]: ".implode(
                    ', ',
                    array_merge(array_diff($en, $keys), array_diff($keys, $en)),
                ),
            );
        }
    }

    public function test_no_duplicate_keys_per_locale(): void
    {
        foreach (self::LOCALES as $locale) {
            $contents = file_get_contents(__DIR__."/../../resources/lang/{$locale}/kinetix.php");
            preg_match_all("/^\s*'([a-z_0-9]+)' =>/m", (string) $contents, $matches);
            $keys = $matches[1];

            $this->assertSame(
                count($keys),
                count(array_unique($keys)),
                "Locale [{$locale}] has duplicate keys.",
            );
        }
    }

    public function test_pagination_keys_exist_with_placeholders(): void
    {
        foreach (self::LOCALES as $locale) {
            $lang = $this->load($locale);

            $this->assertArrayHasKey('showing_records', $lang, "[{$locale}] missing showing_records");
            $this->assertArrayHasKey('no_records', $lang, "[{$locale}] missing no_records");
            $this->assertArrayHasKey('page_of', $lang, "[{$locale}] missing page_of");

            // showing_records uses :from / :to / :total; page_of uses :current / :total.
            foreach ([':from', ':to', ':total'] as $token) {
                $this->assertStringContainsString($token, $lang['showing_records']);
            }
            $this->assertStringContainsString(':current', $lang['page_of']);
            $this->assertStringContainsString(':total', $lang['page_of']);
        }
    }
}
