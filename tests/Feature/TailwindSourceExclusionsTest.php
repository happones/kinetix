<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\TailwindSourceExclusions;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * Tailwind v4 scans the published agent skills (Markdown full of quoted class
 * names) unless the stylesheet says `@source not`, so install/upgrade write
 * those lines and doctor checks them.
 */
class TailwindSourceExclusionsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/kinetix_tailwind_'.uniqid();
        File::ensureDirectoryExists($this->base.'/resources/css');

        $this->app->setBasePath($this->base);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    public function test_it_excludes_the_skills_directory_after_the_last_import(): void
    {
        $this->seedProject();

        $this->assertSame(['../../.claude/skills'], TailwindSourceExclusions::ensure());

        $this->assertSame(
            "@import 'tailwindcss';\n@source '../views';\n"
            .TailwindSourceExclusions::MARKER."\n@source not '../../.claude/skills';\n\nbody { color: red; }\n",
            $this->css(),
        );
    }

    public function test_a_multi_line_import_is_not_split(): void
    {
        $this->seedProject(css: "@import 'tailwindcss';\n@import 'tw-animate-css'\n    layer(base);\n\nbody { color: red; }\n");

        TailwindSourceExclusions::ensure();

        $this->assertSame(
            "@import 'tailwindcss';\n@import 'tw-animate-css'\n    layer(base);\n"
            .TailwindSourceExclusions::MARKER."\n@source not '../../.claude/skills';\n\nbody { color: red; }\n",
            $this->css(),
        );
    }

    /**
     * The installer's own Sonner line ends in a comment, not `;`: the
     * exclusions still go right after it, never into the `@theme` block below.
     */
    public function test_the_installers_sonner_line_is_a_complete_statement(): void
    {
        $sonner = "@import 'vue-sonner/style.css'; /* Kinetix toasts — without this they render unstyled */";
        $this->seedProject(css: "@import \"tailwindcss\";\n{$sonner}\n\n@theme inline {\n    --font-sans: Inter;\n    --radius-lg: 1rem;\n}\n");

        TailwindSourceExclusions::ensure();

        $this->assertSame(
            "@import \"tailwindcss\";\n{$sonner}\n".TailwindSourceExclusions::MARKER
            ."\n@source not '../../.claude/skills';\n\n@theme inline {\n    --font-sans: Inter;\n    --radius-lg: 1rem;\n}\n",
            $this->css(),
        );
    }

    public function test_a_trailing_comment_after_an_import_is_skipped(): void
    {
        $this->seedProject(css: "@import 'tailwindcss'; /* note */\n@theme {\n    --color-x: red;\n}\n");

        TailwindSourceExclusions::ensure();

        $this->assertStringStartsWith(
            "@import 'tailwindcss'; /* note */\n".TailwindSourceExclusions::MARKER."\n@source not '../../.claude/skills';\n@theme {",
            $this->css(),
        );
    }

    public function test_an_import_followed_directly_by_a_block_keeps_the_block_intact(): void
    {
        $this->seedProject(css: "@import 'tailwindcss';\n@theme { --color-x: red; --color-y: blue; }\n");

        TailwindSourceExclusions::ensure();

        $this->assertSame(
            "@import 'tailwindcss';\n".TailwindSourceExclusions::MARKER."\n@source not '../../.claude/skills';\n@theme { --color-x: red; --color-y: blue; }\n",
            $this->css(),
        );
    }

    public function test_a_multi_line_comment_between_imports_is_skipped(): void
    {
        $this->seedProject(css: "@import 'tailwindcss';\n/* the animations;\n   come next; */\n@import 'tw-animate-css';\n.x { color: red; }\n");

        TailwindSourceExclusions::ensure();

        $this->assertSame(
            "@import 'tailwindcss';\n/* the animations;\n   come next; */\n@import 'tw-animate-css';\n"
            .TailwindSourceExclusions::MARKER."\n@source not '../../.claude/skills';\n.x { color: red; }\n",
            $this->css(),
        );
    }

    /**
     * A stylesheet that already carries an `@source` inside a block (what the
     * old placement could produce) is left exactly as it is, and doctor says
     * where the misplaced line is.
     */
    public function test_an_already_corrupted_stylesheet_is_not_made_worse(): void
    {
        $css = "@import 'tailwindcss';\n@theme inline {\n    --font-sans: Inter;\n"
            ."@source not '../../.agents/skills';\n    --radius-lg: 1rem;\n}\n";
        $this->seedProject(css: $css);

        $this->assertSame([4], TailwindSourceExclusions::misplaced());
        $this->assertNotNull(TailwindSourceExclusions::placementProblem());
        $this->assertSame([], TailwindSourceExclusions::ensure());
        $this->assertSame($css, $this->css());

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('has an @import/@source inside a block')
            ->expectsOutputToContain('line 4');
    }

    public function test_without_a_safe_place_nothing_is_written_and_doctor_warns(): void
    {
        $css = "@import 'tailwindcss'; .x { color: red; }\n";
        $this->seedProject(css: $css);

        $this->assertSame([], TailwindSourceExclusions::ensure());
        $this->assertSame($css, $this->css());

        $this->artisan('kinetix:upgrade')->doesntExpectOutputToContain('Tailwind source exclusions');
        $this->assertSame($css, $this->css());

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('lets Tailwind scan the agent skills');
    }

    public function test_a_byte_order_mark_stays_first_and_the_import_stays_whole(): void
    {
        $this->seedProject(css: "\xEF\xBB\xBF@import 'tailwindcss';\n\nbody { color: red; }\n");

        TailwindSourceExclusions::ensure();

        $this->assertSame(
            "\xEF\xBB\xBF@import 'tailwindcss';\n".TailwindSourceExclusions::MARKER
            ."\n@source not '../../.claude/skills';\n\nbody { color: red; }\n",
            $this->css(),
        );
    }

    public function test_nothing_is_written_above_a_leading_charset(): void
    {
        $this->seedProject(css: "@charset \"UTF-8\";\n@import 'tailwindcss';\n.x { color: red; }\n");

        TailwindSourceExclusions::ensure();

        $this->assertSame(
            "@charset \"UTF-8\";\n@import 'tailwindcss';\n".TailwindSourceExclusions::MARKER
            ."\n@source not '../../.claude/skills';\n.x { color: red; }\n",
            $this->css(),
        );
    }

    public function test_crlf_line_endings_are_kept(): void
    {
        $this->seedProject(css: "@import 'tailwindcss';\r\n\r\nbody { color: red; }\r\n");

        TailwindSourceExclusions::ensure();

        $css = $this->css();

        $this->assertSame(
            "@import 'tailwindcss';\r\n".TailwindSourceExclusions::MARKER."\r\n@source not '../../.claude/skills';\r\n\r\nbody { color: red; }\r\n",
            $css,
        );
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $css));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->seedProject();

        TailwindSourceExclusions::ensure();
        $first = $this->css();

        $this->assertSame([], TailwindSourceExclusions::ensure());
        $this->assertSame($first, $this->css());
    }

    public function test_every_skills_directory_holding_kinetix_skills_is_excluded(): void
    {
        $this->seedProject();
        $this->seedSkills('.agents/skills');
        // A host's own skills, without Kinetix's: not Kinetix's to exclude.
        File::ensureDirectoryExists($this->base.'/.opencode/skills/my-skill');
        config()->set('kinetix.skills_path', '.opencode/skills');

        $this->assertSame(['../../.claude/skills', '../../.agents/skills'], TailwindSourceExclusions::ensure());
    }

    public function test_the_configured_skills_path_is_excluded(): void
    {
        $this->seedProject(skills: 'docs/agents/skills');
        config()->set('kinetix.skills_path', 'docs/agents/skills');

        $this->assertSame(['../../docs/agents/skills'], TailwindSourceExclusions::ensure());
    }

    public function test_an_existing_exclusion_of_a_parent_directory_is_respected(): void
    {
        $this->seedProject(css: "@import 'tailwindcss';\n@source not \"../../.claude\";\n");

        $this->assertSame([], TailwindSourceExclusions::ensure());
        $this->assertStringNotContainsString(TailwindSourceExclusions::MARKER, $this->css());
    }

    public function test_a_tailwind_older_than_4_1_is_skipped(): void
    {
        $this->seedProject(tailwind: '4.0.17');

        $this->assertStringContainsString('4.0.17', (string) TailwindSourceExclusions::skipReason());
        $this->assertSame([], TailwindSourceExclusions::ensure());
        $this->assertStringNotContainsString('@source not', $this->css());
    }

    public function test_it_is_skipped_without_node_modules(): void
    {
        $this->seedProject(tailwind: null);

        $this->assertNotNull(TailwindSourceExclusions::skipReason());
        $this->assertSame([], TailwindSourceExclusions::ensure());
    }

    public function test_it_is_skipped_when_turned_off(): void
    {
        $this->seedProject();
        config()->set('kinetix.tailwind.exclude_agent_skills', false);

        $this->assertSame([], TailwindSourceExclusions::ensure());
        $this->assertStringNotContainsString('@source not', $this->css());
    }

    public function test_it_is_skipped_without_kinetix_skills(): void
    {
        $this->seedProject(skills: null);

        $this->assertSame([], TailwindSourceExclusions::directories());
        $this->assertSame([], TailwindSourceExclusions::ensure());
    }

    public function test_upgrade_excludes_a_skills_directory_that_appeared_since_the_install(): void
    {
        $this->seedProject();
        TailwindSourceExclusions::ensure();

        $this->seedSkills('.agents/skills');

        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('Tailwind source exclusions')
            ->assertSuccessful();

        $css = $this->css();
        $this->assertSame(1, substr_count($css, TailwindSourceExclusions::MARKER));
        $this->assertStringContainsString("@source not '../../.claude/skills';\n@source not '../../.agents/skills';", $css);
    }

    public function test_upgrade_skips_an_old_tailwind_silently(): void
    {
        $this->seedProject(tailwind: '4.0.0');

        $this->artisan('kinetix:upgrade')
            ->doesntExpectOutputToContain('Tailwind')
            ->assertSuccessful();

        $this->assertStringNotContainsString('@source not', $this->css());
    }

    public function test_doctor_warns_when_the_exclusion_is_missing_and_is_ok_once_it_is_there(): void
    {
        $this->seedProject();

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('lets Tailwind scan the agent skills')
            ->expectsOutputToContain("@source not '../../.claude/skills';");

        TailwindSourceExclusions::ensure();

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('agent skills excluded from the class scan');
    }

    public function test_doctor_says_nothing_on_a_tailwind_without_source_not(): void
    {
        $this->seedProject(tailwind: '3.4.17');

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('agent skills');
    }

    private function seedProject(
        ?string $tailwind = '4.1.11',
        ?string $skills = '.claude/skills',
        string $css = "@import 'tailwindcss';\n@source '../views';\n\nbody { color: red; }\n",
    ): void {
        File::put($this->base.'/resources/css/app.css', $css);

        if ($tailwind !== null) {
            File::ensureDirectoryExists($this->base.'/node_modules/tailwindcss');
            File::put($this->base.'/node_modules/tailwindcss/package.json', json_encode(['name' => 'tailwindcss', 'version' => $tailwind]));
        }

        if ($skills !== null) {
            $this->seedSkills($skills);
        }
    }

    private function seedSkills(string $path): void
    {
        File::ensureDirectoryExists($this->base.'/'.$path.'/kinetix-tables');
        File::put($this->base.'/'.$path.'/kinetix-tables/SKILL.md', "# Tables\n");
    }

    private function css(): string
    {
        return (string) File::get($this->base.'/resources/css/app.css');
    }
}
