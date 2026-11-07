<?php

declare(strict_types=1);

/**
 * Formats the shipped agent skills exactly as Laravel Boost writes them.
 *
 * The skills under resources/boost/skills reach a host's skills directory
 * through two writers: `vendor:publish --tag=kinetix-skills` copies the bytes,
 * and Boost's `boost:update` rewrites them through its MarkdownFormatter
 * (`format(trim($source))`, then a trailing newline). When the two disagree,
 * every `composer install` flips the committed copy and dirties a clean
 * checkout. Shipping sources that are already in Boost's form makes both
 * writers produce the same bytes.
 *
 * Mirrors Laravel\Boost\Install\MarkdownFormatter + Support\Fences (v2.10).
 * tests/Feature/SkillsPublishTest guards the sources with the same function.
 *
 *     php scripts/format-skills.php           rewrite the sources in place
 *     php scripts/format-skills.php --check   list the files that would change
 */
function kinetix_boost_format_skill(string $source): string
{
    $content = str_replace(["\r\n", "\r"], "\n", trim($source));

    // Fenced code blocks are masked first: a "# " line inside one is code.
    $fences = [];
    $masked = preg_replace_callback(
        '/^ {0,3}(?<fence>`{3,}|~{3,})[^\n]*\n.*?(?:^ {0,3}\k<fence>[`~]*[ \t]*$|\z)/ms',
        static function (array $matches) use (&$fences): string {
            $placeholder          = "\0".count($fences)."\0";
            $fences[$placeholder] = $matches[0];

            return $placeholder;
        },
        $content,
    );

    if ($masked !== null) {
        $spaced  = (string) preg_replace('/(?<!\n)\n(#{1,4} )/m', "\n\n$1", $masked);
        $spaced  = (string) preg_replace('/^(#{1,4} .+)\n(?!\n)/m', "$1\n\n", $spaced);
        $spaced  = (string) preg_replace('/\n{3,}/', "\n\n", $spaced);
        $content = str_replace(array_keys($fences), array_values($fences), $spaced);
    }

    return str_ends_with($content, "\n") ? $content : $content."\n";
}

/**
 * @return list<string> Every markdown file the skills publish ships.
 */
function kinetix_skill_markdown_files(string $root): array
{
    $files    = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/boost/skills', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.md')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

if (PHP_SAPI === 'cli' && realpath((string) ($argv[0] ?? '')) === __FILE__) {
    $check   = in_array('--check', $argv, true);
    $changed = [];

    foreach (kinetix_skill_markdown_files(dirname(__DIR__)) as $path) {
        $source    = (string) file_get_contents($path);
        $formatted = kinetix_boost_format_skill($source);

        if ($formatted === $source) {
            continue;
        }

        $changed[] = $path;

        if (! $check) {
            file_put_contents($path, $formatted);
        }
    }

    foreach ($changed as $path) {
        fwrite(STDOUT, ($check ? 'needs formatting: ' : 'formatted: ').$path.PHP_EOL);
    }

    exit($check && $changed !== [] ? 1 : 0);
}
