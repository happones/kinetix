<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class GeneratorCommand extends Command
{
    /**
     * The sub-namespace/directory under app/Kinetix (e.g. 'Actions').
     */
    abstract protected function subNamespace(): string;

    /**
     * Build the PHP file contents for the generated class.
     */
    abstract protected function stub(string $class): string;

    public function handle(): int
    {
        $class     = Str::studly($this->argument('name'));
        $directory = app_path('Kinetix/'.$this->subNamespace());
        $path      = $directory.'/'.$class.'.php';

        if (File::exists($path) && ! $this->option('force')) {
            $this->error("{$class} already exists at [{$this->relativePath($path)}]. Use --force to overwrite.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($directory);
        File::put($path, $this->stub($class));

        $this->info("Created [{$this->relativePath($path)}].");

        return self::SUCCESS;
    }

    /**
     * The namespace the generated class lives in.
     */
    protected function namespace(): string
    {
        return 'App\\Kinetix\\'.str_replace('/', '\\', $this->subNamespace());
    }

    protected function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
