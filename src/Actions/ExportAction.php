<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Happones\Kinetix\Exports\Exporter;

class ExportAction extends Action
{
    /**
     * The exporter this action was wired to (needed to re-wire the start URL
     * when a relation manager scopes the export to its parent).
     *
     * @var class-string<Exporter>|null
     */
    protected ?string $exporterClass = null;

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
        $this->exporterClass = $exporterClass;

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

    /**
     * @return class-string<Exporter>|null
     */
    public function getExporterClass(): ?string
    {
        return $this->exporterClass;
    }

    /**
     * Scope the export to a relation manager's parent relationship (internal —
     * RelationManager wires it): the signed descriptor travels in the start
     * URL, the endpoint validates it, and the export query is narrowed to the
     * parent's related records on top of the exporter's own query().
     */
    public function scopeToRelation(string $descriptor): static
    {
        if ($this->exporterClass === null) {
            return $this;
        }

        /** @var Exporter $exporter */
        $exporter = new $this->exporterClass;

        return $this->request(
            route('kinetix.exports.start', [
                'exporter' => $this->exporterClass::token(),
                'relation' => $descriptor,
            ]),
            ['method' => 'post', 'toast' => $exporter->getStartedNotificationBody()],
        );
    }
}
