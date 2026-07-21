<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeRolesPageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:make-roles-page
                            {--force : Overwrite the page if it already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffold a ready-made Roles & Permissions page (role cards + read-only permission matrix + editor modal)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $directory = resource_path('js/pages/Kinetix/Roles');
        $filePath  = "{$directory}/Index.vue";

        if (File::exists($filePath) && ! $this->option('force')) {
            $this->error('resources/js/pages/Kinetix/Roles/Index.vue already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // The page is a thin mount: KinetixRolesOverview owns the role cards,
        // the read-only permission matrix and the editor modal, and it talks to
        // Kinetix's own (team-aware) permission endpoints — no controller code.
        $template = <<<'VUE'
<script setup lang="ts">
import KinetixCan from '@/components/kinetix/KinetixCan.vue';
import KinetixRolesOverview from '@/components/kinetix/KinetixRolesOverview.vue';
</script>

<template>
  <!-- Role cards + read-only permission matrix (modules × roles) + the editor
       modal. Data flows through Kinetix's built-in permission endpoints, so
       this page needs no controller — only the route below. -->
  <div class="flex h-full min-w-0 flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
    <KinetixCan permission="roles.manage">
      <KinetixRolesOverview />

      <template #denied>
        <p class="text-sm text-muted-foreground">
          You are not allowed to manage roles.
        </p>
      </template>
    </KinetixCan>
  </div>
</template>
VUE;

        File::put($filePath, $template);
        $this->line('Created Vue Page: [resources/js/pages/Kinetix/Roles/Index.vue]');

        $this->info("\nRoles page scaffolded successfully!");
        $this->comment('Next steps:');
        $this->line('1. Add the page route to your routes/web.php file:');

        if (config('kinetix.teams', false)) {
            $this->line('   // Team-aware: nest under the {current_team} segment Kinetix uses.');
            $this->line("   Route::prefix('{current_team}')->group(function () {");
            $this->line("       Route::get('roles', fn () => inertia('Kinetix/Roles/Index'))->name('roles.index');");
            $this->line('   });');
        } else {
            $this->line("   Route::get('roles', fn () => inertia('Kinetix/Roles/Index'))->name('roles.index');");
        }

        $this->line('2. Enable the permissions module (`kinetix.permissions.enabled`) if you have not already.');

        return self::SUCCESS;
    }
}
