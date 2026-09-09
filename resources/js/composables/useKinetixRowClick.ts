import { router } from '@inertiajs/vue3';
import type { KinetixAction, KinetixTableRecord } from '@/types/kinetix';

/**
 * Whole-row activation for Kinetix tables (server-driven and client-side).
 *
 * The server decides what a row does — `recordUrl` (navigate) or
 * `recordAction` (run one of the row's own actions, e.g. a modal `view`) — and
 * this composable turns a click or an Enter press into that behaviour while
 * keeping every nested control inert: a click that lands on a button, link,
 * input or menu never activates the row, so the actions dropdown, inline
 * editors and selection checkboxes keep working untouched.
 */
export interface UseKinetixRowClick {
    isRowClickable: (record: KinetixTableRecord) => boolean;
    handleRowClick: (record: KinetixTableRecord, event: MouseEvent) => void;
    handleRowKeydown: (
        record: KinetixTableRecord,
        event: KeyboardEvent,
    ) => void;
}

/**
 * Elements whose own interaction must win over the row. `[role]` covers Reka
 * triggers and menu items rendered as non-button elements.
 */
const NESTED_CONTROL_SELECTOR = [
    'button',
    'a',
    'input',
    'select',
    'textarea',
    'label',
    '[contenteditable]',
    '[role="button"]',
    '[role="link"]',
    '[role="menuitem"]',
    '[role="checkbox"]',
    '[role="switch"]',
].join(',');

const isNestedControl = (target: EventTarget | null): boolean =>
    target instanceof Element &&
    target.closest(NESTED_CONTROL_SELECTOR) !== null;

/**
 * Lift grouped actions out of their dropdown so `recordAction` can name an
 * action that lives inside an ActionGroup.
 */
export const flattenRowActions = (
    actions: KinetixAction[] | undefined,
): KinetixAction[] =>
    (actions ?? []).flatMap((action) =>
        action.type === 'group' ? (action.actions ?? []) : [action],
    );

export const findRowAction = (
    record: KinetixTableRecord,
): KinetixAction | null => {
    if (!record.recordAction) {
        return null;
    }

    return (
        flattenRowActions(record.actions).find(
            (action) => action.name === record.recordAction,
        ) ?? null
    );
};

export const isRowClickable = (record: KinetixTableRecord): boolean =>
    !!record.recordUrl || findRowAction(record) !== null;

const openInNewTab = (url: string): void => {
    // noopener,noreferrer: the opened page can't reach back via window.opener.
    window.open(url, '_blank', 'noopener,noreferrer');
};

export function useKinetixRowClick(options: {
    /** Runs a row action exactly like its own button would (modals included). */
    runAction: (action: KinetixAction, record: KinetixTableRecord) => void;
}): UseKinetixRowClick {
    const activate = (
        record: KinetixTableRecord,
        wantsNewTab: boolean,
    ): void => {
        if (record.recordUrl) {
            if (wantsNewTab || record.recordUrlInNewTab) {
                openInNewTab(record.recordUrl);

                return;
            }

            router.visit(record.recordUrl);

            return;
        }

        const action = findRowAction(record);

        if (!action) {
            return;
        }

        options.runAction(action, record);
    };

    const handleRowClick = (
        record: KinetixTableRecord,
        event: MouseEvent,
    ): void => {
        if (isNestedControl(event.target)) {
            return;
        }

        // Selecting text inside a cell must not navigate away from it.
        if ((window.getSelection?.()?.toString() ?? '') !== '') {
            return;
        }

        activate(record, event.metaKey || event.ctrlKey);
    };

    const handleRowKeydown = (
        record: KinetixTableRecord,
        event: KeyboardEvent,
    ): void => {
        // Only the row itself: Enter inside a nested control is that control's.
        if (event.key !== 'Enter' || event.target !== event.currentTarget) {
            return;
        }

        event.preventDefault();
        activate(record, event.metaKey || event.ctrlKey);
    };

    return { isRowClickable, handleRowClick, handleRowKeydown };
}
