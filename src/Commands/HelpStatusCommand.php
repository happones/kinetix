<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Help\HelpManager;
use Illuminate\Console\Command;

/**
 * Translation coverage for the in-app manual: one row per article, one column
 * per locale, so a team can see at a glance what is still missing. `--strict`
 * turns a gap into a non-zero exit, which makes it usable as a CI gate.
 */
class HelpStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:help-status
                            {--locale=* : Only report these locales (defaults to every supported locale)}
                            {--strict : Exit with a failure code when a translation is missing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Report Help Center translation coverage per article and locale';

    public function handle(HelpManager $manager): int
    {
        $articles = $manager->articles(locale: $manager->baseLocale());

        if ($articles === []) {
            $this->warn('No help articles found in '.$manager->path().'.');

            return self::SUCCESS;
        }

        $locales = $this->locales($manager);
        $missing = 0;
        $rows    = [];

        foreach ($articles as $article) {
            $available = $manager->availableLocales($article->slug);
            $row       = [$article->slug];

            foreach ($locales as $locale) {
                $has = in_array($locale, $available, true);
                $missing += $has ? 0 : 1;
                $row[] = $has ? '<info>✓</info>' : '<comment>—</comment>';
            }

            $rows[] = $row;
        }

        $this->table(['Article', ...$locales], $rows);

        $total = count($articles) * count($locales);
        $this->line(sprintf(
            '%d/%d translations present across %d article(s) and %d locale(s).',
            $total - $missing,
            $total,
            count($articles),
            count($locales),
        ));

        if ($missing > 0) {
            $this->comment('Scaffold a missing one with: php artisan kinetix:make-help-page --locale=<code> --from=<slug>');
        }

        return $missing > 0 && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The locales to report on: `--locale` when given, else every supported
     * one (the base locale always first).
     *
     * @return array<int, string>
     */
    protected function locales(HelpManager $manager): array
    {
        /** @var array<int, string> $requested */
        $requested = array_filter((array) $this->option('locale'));

        if ($requested !== []) {
            return array_values(array_unique(array_map('strval', $requested)));
        }

        return array_values(array_unique([
            $manager->baseLocale(),
            ...$manager->supportedLocales(),
        ]));
    }
}
