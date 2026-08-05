<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Happones\Kinetix\Exports\Exporter;

class ExportAction extends Action
{
    public static function make(string $name = 'export'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'export')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.export'))
            ->icon('download')
            ->color('gray');
    }

    /**
     * Wire the action to the given exporter. Placed in `toolbarActions`/
     * `headerActions` it exports the exporter's query; placed in `bulkActions`
     * it exports only the selected rows (their ids are sent automatically).
     *
     * @param class-string<Exporter> $exporterClass
     */
    public function exporter(string $exporterClass): static
    {
        /** @var Exporter $exporter */
        $exporter = new $exporterClass;

        // Fire a background POST (not an Inertia visit, so the JSON response
        // won't trigger Inertia's modal). The exporter travels as a signed
        // token in the URL; the built-in exports/start endpoint queues it and
        // the user is notified with a download link when it finishes.
        $this->request(
            route('kinetix.exports.start', ['exporter' => $exporterClass::token()]),
            ['method' => 'post', 'toast' => $exporter->getStartedNotificationBody()],
        );

        return $this;
    }
}
