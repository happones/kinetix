<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Happones\Kinetix\Imports\Importer;

class ImportAction extends Action
{
    public static function make(string $name = 'import'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'import')
    {
        parent::__construct($name);

        $this->label((string) trans('kinetix.import'))
            ->icon('upload')
            ->color('gray');
    }

    /**
     * Wire the action to the given importer. Clicking it opens the import
     * preview modal (`<KinetixImportModal>` must be mounted once in the layout),
     * carrying the importer as a signed token — plus the template filename when
     * the importer offers a downloadable template (default on; opt out with
     * `protected bool $downloadableTemplate = false`).
     *
     * @param class-string<Importer> $importerClass
     */
    public function importer(string $importerClass): static
    {
        /** @var Importer $importer */
        $importer = new $importerClass;

        $this->dispatch('open-importer', [
            'importer' => $importerClass::token(),
            'template' => $importer->hasDownloadableTemplate() ? $importer->getTemplateFileName() : null,
        ]);

        return $this;
    }
}
