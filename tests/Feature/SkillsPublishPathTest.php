<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;

/**
 * Agent tooling doesn't agree on a skills directory, so the publish target is
 * configurable. It is resolved when the provider boots, i.e. from config.
 */
class SkillsPublishPathTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.skills_path', '.agents/skills');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('.agents'));
        File::deleteDirectory(base_path('.claude'));

        parent::tearDown();
    }

    public function test_the_target_directory_is_configurable(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-skills'])->assertSuccessful();

        $this->assertFileExists(base_path('.agents/skills/kinetix-permissions/SKILL.md'));
        $this->assertDirectoryDoesNotExist(base_path('.claude/skills'));
    }
}
