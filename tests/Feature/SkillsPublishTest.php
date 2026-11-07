<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\PublishedFiles;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * The bundled agent skills are useless while they sit in `vendor/` — agents only
 * read the project's own skills directory. These cover the distribution.
 */
class SkillsPublishTest extends TestCase
{
    protected function tearDown(): void
    {
        // The testbench skeleton is shared — leave no published files behind.
        File::deleteDirectory(base_path('.claude'));
        File::deleteDirectory(base_path('.agents'));
        File::delete(base_path('boost.json'));

        parent::tearDown();
    }

    public function test_the_tag_publishes_every_bundled_skill(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-skills'])->assertSuccessful();

        $target = base_path('.claude/skills');

        $this->assertFileExists($target.'/kinetix-permissions/SKILL.md');
        $this->assertFileExists($target.'/kinetix-membership/SKILL.md');

        $this->assertSameSize(
            File::directories(__DIR__.'/../../resources/boost/skills'),
            File::directories($target),
        );
    }

    /**
     * Boost's `boost:update` writes these skills through its markdown
     * formatter while `kinetix:upgrade` copies the raw bytes. A source that
     * isn't already in Boost's form makes the two writers fight, and every
     * `composer install` dirties the host's committed copy.
     */
    public function test_every_skill_is_already_in_the_form_boost_writes(): void
    {
        require_once __DIR__.'/../../scripts/format-skills.php';

        $root     = dirname(__DIR__, 2);
        $unstable = [];

        foreach (kinetix_skill_markdown_files($root) as $path) {
            $source = (string) File::get($path);

            if (kinetix_boost_format_skill($source) !== $source) {
                $unstable[] = substr($path, strlen($root) + 1);
            }
        }

        $this->assertSame([], $unstable, 'Run `php scripts/format-skills.php` to format: '.implode(', ', $unstable));
    }

    public function test_the_boost_formatter_mirror_spaces_headings_outside_fences_only(): void
    {
        require_once __DIR__.'/../../scripts/format-skills.php';

        $source = "# Title\nText\n\n\n\n## Next\n```md\n# not a heading\n```\n";

        $this->assertSame(
            "# Title\n\nText\n\n## Next\n\n```md\n# not a heading\n```\n",
            kinetix_boost_format_skill($source),
        );
    }

    public function test_the_permissions_and_membership_skills_document_the_integration_traps(): void
    {
        foreach (['kinetix-permissions', 'kinetix-membership'] as $skill) {
            $content = File::get(__DIR__."/../../resources/boost/skills/{$skill}/SKILL.md");

            $this->assertStringContainsString('## Common integration mistakes', $content);
            $this->assertStringContainsString('kinetix.route_prefix', $content);
            $this->assertStringContainsString('kinetix:routes', $content);
            $this->assertStringContainsString('HandleInertiaRequests', $content);
        }
    }

    public function test_the_upgrade_command_refreshes_adopted_skills(): void
    {
        $skill = base_path('.claude/skills/kinetix-permissions/SKILL.md');

        File::ensureDirectoryExists(dirname($skill));
        File::put($skill, 'STALE LOCAL COPY');

        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('agent skills')
            ->assertSuccessful();

        $this->assertNotSame('STALE LOCAL COPY', File::get($skill));
    }

    /**
     * Boost writes the skills `boost.json` lists on `boost:update`. Publishing
     * them again on every `composer install` made two writers of one committed
     * file, so a clean checkout went dirty on machines where nothing changed.
     */
    public function test_the_upgrade_leaves_skills_to_boost_when_boost_writes_them_here(): void
    {
        $this->writeBoostJson(['claude_code'], $this->shippedSkills());

        $skill = base_path('.claude/skills/kinetix-permissions/SKILL.md');
        File::ensureDirectoryExists(dirname($skill));
        File::put($skill, 'WRITTEN BY BOOST');

        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('left to Laravel Boost')
            ->expectsOutputToContain('nothing else to upgrade')
            ->doesntExpectOutputToContain('no published components/translations/skills found')
            ->assertSuccessful();

        $this->assertSame('WRITTEN BY BOOST', File::get($skill));
        $this->assertFalse(PublishedFiles::upgradePublishesSkills());
    }

    public function test_boost_writing_another_directory_keeps_the_publish(): void
    {
        // Junie's skills live in .junie/skills; nothing would refresh .claude/skills.
        $this->writeBoostJson(['junie'], $this->shippedSkills());

        $this->assertStaleSkillIsRepublished();
    }

    public function test_a_boost_agent_path_override_is_honoured(): void
    {
        $this->writeBoostJson(['junie'], $this->shippedSkills());
        config()->set('boost.agents.junie.skills_path', '.claude/skills');

        $this->assertTrue(PublishedFiles::boostManagesSkills());
    }

    public function test_a_published_skill_boost_does_not_list_keeps_the_publish(): void
    {
        $this->writeBoostJson(['claude_code'], ['kinetix-forms']);

        // kinetix-permissions sits in the directory but Boost won't write it.
        $this->assertStaleSkillIsRepublished();
    }

    public function test_a_malformed_boost_json_keeps_the_publish(): void
    {
        File::put(base_path('boost.json'), '{not json');

        $this->assertStaleSkillIsRepublished();
    }

    public function test_a_boost_json_without_kinetix_skills_keeps_the_publish(): void
    {
        $this->writeBoostJson(['claude_code'], ['laravel-best-practices']);

        $skill = base_path('.claude/skills/kinetix-permissions/SKILL.md');
        File::ensureDirectoryExists(dirname($skill));
        File::put($skill, 'STALE LOCAL COPY');

        $this->artisan('kinetix:upgrade')->expectsOutputToContain('agent skills')->assertSuccessful();

        $this->assertNotSame('STALE LOCAL COPY', File::get($skill));
    }

    public function test_the_config_switch_overrides_the_boost_detection(): void
    {
        $this->writeBoostJson(['claude_code'], $this->shippedSkills());
        config()->set('kinetix.skills_publish_on_upgrade', true);

        $skill = base_path('.claude/skills/kinetix-permissions/SKILL.md');
        File::ensureDirectoryExists(dirname($skill));
        File::put($skill, 'STALE LOCAL COPY');

        $this->artisan('kinetix:upgrade')->expectsOutputToContain('agent skills')->assertSuccessful();
        $this->assertNotSame('STALE LOCAL COPY', File::get($skill));

        File::delete(base_path('boost.json'));
        config()->set('kinetix.skills_publish_on_upgrade', false);
        File::put($skill, 'KEPT');

        $this->artisan('kinetix:upgrade')->assertSuccessful();
        $this->assertSame('KEPT', File::get($skill));
    }

    public function test_the_upgrade_command_skips_skills_that_were_never_published(): void
    {
        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('nothing to upgrade')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(base_path('.claude/skills'));
    }

    /**
     * @param list<string> $agents
     * @param list<string> $skills
     */
    protected function writeBoostJson(array $agents, array $skills): void
    {
        File::put(base_path('boost.json'), (string) json_encode(['agents' => $agents, 'skills' => $skills]));
    }

    /**
     * @return list<string>
     */
    protected function shippedSkills(): array
    {
        return array_map('basename', File::directories(__DIR__.'/../../resources/boost/skills'));
    }

    protected function assertStaleSkillIsRepublished(): void
    {
        $skill = base_path('.claude/skills/kinetix-permissions/SKILL.md');
        File::ensureDirectoryExists(dirname($skill));
        File::put($skill, 'STALE LOCAL COPY');

        $this->artisan('kinetix:upgrade')->expectsOutputToContain('agent skills')->assertSuccessful();

        $this->assertNotSame('STALE LOCAL COPY', File::get($skill));
    }
}
