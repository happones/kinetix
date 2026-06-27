<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A rich text / WYSIWYG field with swappable editor drivers. The default driver
 * comes from `config('kinetix.forms.rich_editor')`; override per field with
 * ->editor() (or the ->basic()/->tiptap()/->markdown() shortcuts).
 *
 *     RichEditor::make('body');                 // config default
 *     RichEditor::make('body')->tiptap();       // rich WYSIWYG (opt-in dep)
 *     RichEditor::make('notes')->markdown();    // Markdown source + preview
 *
 * `basic` and `markdown` need no extra packages; `tiptap` requires
 *
 * @tiptap/core + @tiptap/starter-kit in the host app (loaded lazily).
 *
 * Stores HTML (basic/tiptap) or Markdown (markdown). HTML is NOT sanitized
 * server-side — escape or sanitize it on output (see docs).
 */
class RichEditor extends Field
{
    public const DRIVERS = ['basic', 'tiptap', 'markdown'];

    protected ?string $editor = null;

    protected function getType(): string
    {
        return 'rich-editor';
    }

    /**
     * Choose the editor driver (basic|tiptap|markdown).
     */
    public function editor(string $driver): static
    {
        $this->editor = in_array($driver, self::DRIVERS, true) ? $driver : null;

        return $this;
    }

    public function basic(): static
    {
        return $this->editor('basic');
    }

    public function tiptap(): static
    {
        return $this->editor('tiptap');
    }

    public function markdown(): static
    {
        return $this->editor('markdown');
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $default      = (string) config('kinetix.forms.rich_editor', 'basic');
        $data->editor = $this->editor ?? (in_array($default, self::DRIVERS, true) ? $default : 'basic');

        return $data;
    }
}
