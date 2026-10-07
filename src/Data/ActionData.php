<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ActionData extends Data
{
    /**
     * @param array<string, mixed>        $dispatchData
     * @param array<string, mixed>|null   $inertiaVisit
     * @param array<int, ActionData>|null $actions
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $icon = null,
        public string $iconPosition = 'before',
        public ?string $url = null,
        public bool $shouldOpenInNewTab = false,
        public string $color = 'primary',
        public string $size = 'sm',
        public string $viewType = 'button',
        public bool $shouldClose = false,
        public bool $shouldMarkAsRead = false,
        public bool $shouldMarkAsUnread = false,
        public ?string $dispatchEvent = null,
        public array $dispatchData = [],
        public ?array $inertiaVisit = null,
        public ?array $httpRequest = null,
        public bool $requiresConfirmation = false,
        public ?string $modalHeading = null,
        public ?string $modalDescription = null,
        public ?string $modalIcon = null,
        public ?string $modalSubmitActionLabel = null,
        public ?string $modalCancelActionLabel = null,
        public string $type = 'action',
        public ?array $actions = null,
        // Force a file download of `url` instead of navigating.
        public bool $isDownload = false,
        // Open `url` in the file-preview modal (image/pdf) instead of navigating.
        public bool $isPreview = false,
        public ?string $previewType = null,
        // Keyboard shortcut (e.g. 'c', 'mod+e') for page/header actions.
        public ?string $shortcut = null,
        // Compact icon-only button (no visible label / outline).
        public bool $isIconButton = false,
        // Opens an in-table record modal ('create'|'edit'|'view'|'delete')
        // instead of navigating/dispatching. See Table::recordModals().
        public ?string $modal = null,
        // True for a BulkAction: the frontend routes it to the signed
        // kinetix.tables.bulk-action endpoint (scope + per-record policy)
        // instead of a host URL/event. See Table::bulkActions().
        public bool $isSecureBulk = false,
        // True for a FormAction: the frontend opens a modal hosting `form` and
        // POSTs the submitted values to the signed kinetix.tables.form-action
        // endpoint (scope + per-record policy + server-side validation).
        public bool $isFormAction = false,
        // The modal's form schema for a FormAction (null otherwise). Serialised
        // from the SAME form class the controller reconstructs to validate the
        // submission, so what the user fills can't drift from what is enforced.
        public ?FormData $form = null,
    ) {}
}
