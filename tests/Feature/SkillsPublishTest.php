<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

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

    public function test_the_upgrade_command_skips_skills_that_were_never_published(): void
    {
        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('nothing to upgrade')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(base_path('.claude/skills'));
    }
}
