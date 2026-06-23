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
     * carrying the importer as a signed token.
     *
     * @param class-string<Importer> $importerClass
     */
    public function importer(string $importerClass): static
    {
        $this->dispatch('open-importer', ['importer' => $importerClass::token()]);

        return $this;
    }
}
