<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakePageCommand extends Command
{
    protected $signature = 'kinetix:make-page
                            {name : The page name (e.g. InventoryAdjust)}
                            {--no-controller : Skip the controller}
                            {--no-view : Skip the Vue page}
                            {--sticky-footer : Pin the footer action bar to the bottom of the scroll container}
                            {--force : Overwrite existing files}';

    protected $description = 'Create a blank Kinetix page: a Page class declaring its header/footer actions, a controller, and a Vue page whose body is yours to fill';

    public function handle(): int
    {
        $name  = Str::studly(Str::replaceLast('Page', '', (string) $this->argument('name')));
        $class = "{$name}Page";
        $slug  = Str::kebab($name);

        $this->writeFile(
            app_path("Kinetix/Pages/{$class}.php"),
            $this->pageClass($class, $name),
            "Page class: [app/Kinetix/Pages/{$class}.php]"
        );

        if (! $this->option('no-controller')) {
            $this->writeFile(
                app_path("Http/Controllers/Kinetix/{$name}Controller.php"),
                $this->controller($name, $class),
                "Controller: [app/Http/Controllers/Kinetix/{$name}Controller.php]"
            );
        }

        if (! $this->option('no-view')) {
            $this->writeFile(
                resource_path("js/pages/Kinetix/{$name}.vue"),
                $this->view($name),
                "Vue page: [resources/js/pages/Kinetix/{$name}.vue]"
            );
        }

        $this->newLine();
        $this->line('  Register the route:');
        $this->newLine();
        $this->line("    use App\\Http\\Controllers\\Kinetix\\{$name}Controller;");
        $this->line("    Route::get('/{$slug}', {$name}Controller::class)->name('{$slug}');");
        $this->newLine();
        $this->line('  The page body is the slot in the Vue file — put whatever you like there.');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function writeFile(string $path, string $contents, string $label): void
    {
        if (File::exists($path) && ! $this->option('force')) {
            $this->warn("Skipped {$label} — it already exists (use --force to overwrite).");

            return;
        }

        File::ensureDirectoryExists(dirname($path));
        // A trailing newline, so the generated file is already clean under the
        // host's own Pint/ESLint run instead of failing it on first commit.
        File::put($path, rtrim($contents, "\n")."\n");
        $this->line("Created {$label}");
    }

    protected function pageClass(string $class, string $name): string
    {
        $heading = Str::headline($name);
        $sticky  = $this->option('sticky-footer') ? "\n\n    protected bool \$stickyFooter = true;" : '';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Kinetix\\Pages;

            use Happones\\Kinetix\\Actions\\Action;
            use Happones\\Kinetix\\Pages\\Page;

            class {$class} extends Page
            {
                protected ?string \$heading = '{$heading}';{$sticky}

                /**
                 * Actions in the page header. Wrap every label in __() — it is your
                 * copy, not Kinetix's.
                 *
                 * @return array<int, Action>
                 */
                protected function buildHeaderActions(): array
                {
                    return [
                        // Action::make('export')
                        //     ->label(__('{$this->langKey($name)}.export'))
                        //     ->icon('download')
                        //     ->color('gray')
                        //     ->url(route('{$this->slug($name)}.export')),
                    ];
                }

                /**
                 * Actions in the page footer — the place for Save / Cancel / Submit.
                 * Order them primary-LAST: below `sm` the row reverses, so the last
                 * action lands on top where the thumb is.
                 *
                 * @return array<int, Action>
                 */
                protected function buildFooterActions(): array
                {
                    return [
                        // Action::make('cancel')
                        //     ->label(__('{$this->langKey($name)}.cancel'))
                        //     ->color('gray')
                        //     ->url(route('dashboard')),

                        // Action::make('save')
                        //     ->label(__('{$this->langKey($name)}.save'))
                        //     ->icon('check')
                        //     ->requiresConfirmation(__('{$this->langKey($name)}.save_confirm'))
                        //     ->inertiaVisit(route('{$this->slug($name)}.store'), ['method' => 'post']),
                    ];
                }
            }
            PHP;
    }

    protected function controller(string $name, string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Http\\Controllers\\Kinetix;

            use App\\Kinetix\\Pages\\{$class};
            use Illuminate\\Http\\Request;
            use Inertia\\Response;

            class {$name}Controller
            {
                public function __invoke(Request \$request): Response
                {
                    return inertia('Kinetix/{$name}', [
                        // The page's chrome: heading, description and both action
                        // bars, authorized per user (an action the user may not run
                        // is dropped before it reaches the browser).
                        'page' => {$class}::make()->toArray(),

                        // Everything the page BODY needs is yours to add here.
                    ]);
                }
            }
            PHP;
    }

    protected function view(string $name): string
    {
        return <<<'VUE'
            <script setup lang="ts">
            import KinetixPageShell from '@/components/kinetix/KinetixPageShell.vue';
            import type { KinetixPageData } from '@/types/kinetix';

            defineProps<{ page: KinetixPageData }>();
            </script>

            <template>
              <!-- The shell renders the header bar, this slot, then the footer bar.
                   The body is yours: a Kinetix table, a form, or your own components. -->
              <div class="flex h-full min-w-0 flex-1 flex-col p-4">
                <KinetixPageShell :page="page">
                  <div class="rounded-xl border border-border bg-card p-6">
                    <p class="text-sm text-muted-foreground">
                      Replace this with the page content.
                    </p>
                  </div>
                </KinetixPageShell>
              </div>
            </template>
            VUE;
    }

    protected function slug(string $name): string
    {
        return Str::kebab($name);
    }

    protected function langKey(string $name): string
    {
        return Str::snake($name);
    }
}
