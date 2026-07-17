<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Tables\Table;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Describes the in-table modal CRUD wiring for a "simple" resource table.
 *
 * When present on a {@see TableData}, KinetixTable hosts the create/edit form
 * and view (infolist) modals itself, so the page is just `<KinetixTable :table>`.
 * The signed `token` (model + resource) authorizes the record endpoint; the
 * frontend fetches a fresh form/infolist per record and persists CRUD through
 * the Kinetix record routes. See {@see Table::recordModals()}.
 */
#[TypeScript]
class RecordModalsData extends Data
{
    /**
     * @param array<string, mixed>|null $createForm blueprint form DTO for the create modal (instant, no round-trip)
     */
    public function __construct(
        public bool $enabled,
        // Signed descriptor: encrypted { model, resource }. Sent with every
        // resolve/store/update/destroy request so the endpoint can trust the
        // model + resource without the client naming them.
        public string $token,
        // 'server' (fetch the fresh record) | 'row' (prefill from the loaded row).
        public string $source = 'server',
        public bool $hasForm = false,
        public bool $hasInfolist = false,
        public ?array $createForm = null,
    ) {}
}
