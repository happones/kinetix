import { parseAbsolute } from '@internationalized/date';
import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { dateKeyOf } from '@/composables/kinetixCalendarDates';
import { useActionConfirmation } from '@/composables/useKinetixActions';
import {
    actionButtonVariant,
    buttonVariants,
} from '@/composables/useShadcnVariants';
import type { KinetixAction, KinetixCalendarEvent } from '@/types/kinetix';

export interface UseKinetixCalendarEventDetailsOptions {
    locale: () => string | undefined;
    tz: () => string;
    showEventDetails: () => boolean;
    /** Notified on every event click, regardless of the built-in popup. */
    onEventClick: (event: KinetixCalendarEvent) => void;
}

export interface UseKinetixCalendarEventDetails {
    selectedEvent: Ref<KinetixCalendarEvent | null>;
    detailsOpen: Ref<boolean>;
    selectedEventActions: ComputedRef<KinetixAction[]>;
    eventRangeLabel: ComputedRef<string>;
    openEvent: (event: KinetixCalendarEvent) => void;
    closeDetails: () => void;
    eventActionClass: (action: KinetixAction) => string;
    handleEventAction: (action: KinetixAction) => void;
    // Passed straight through from the shared action-confirmation flow.
    pendingAction: Ref<KinetixAction | null>;
    isConfirmOpen: Ref<boolean>;
    processing: Ref<boolean>;
    onConfirmAction: () => void;
    onCancelAction: () => void;
}

/**
 * The event-details popup (modal/sheet) state, plus the shared action flow so
 * `requiresConfirmation()` event actions open the same KinetixConfirmModal used
 * across tables and infolists.
 */
export function useKinetixCalendarEventDetails(
    options: UseKinetixCalendarEventDetailsOptions,
): UseKinetixCalendarEventDetails {
    const selectedEvent = ref<KinetixCalendarEvent | null>(null);
    const detailsOpen = ref(false);

    const openEvent = (event: KinetixCalendarEvent): void => {
        options.onEventClick(event);

        if (options.showEventDetails()) {
            selectedEvent.value = event;
            detailsOpen.value = true;
        }
    };

    const closeDetails = (): void => {
        detailsOpen.value = false;
    };

    const {
        pendingAction,
        isConfirmOpen,
        processing,
        requestAction,
        confirm: onConfirmAction,
        cancel: onCancelAction,
    } = useActionConfirmation();

    const handleEventAction = (action: KinetixAction): void => {
        requestAction(action);
    };

    const eventActionClass = (action: KinetixAction): string =>
        buttonVariants({
            variant: action.color ? actionButtonVariant(action.color) : 'ghost',
            size: action.isIconButton ? 'icon-sm' : 'sm',
        });

    const dateFmt = computed(
        () =>
            new Intl.DateTimeFormat(options.locale(), {
                dateStyle: 'long',
                timeZone: options.tz(),
            }),
    );
    const timeFmt = computed(
        () =>
            new Intl.DateTimeFormat(options.locale(), {
                timeStyle: 'short',
                timeZone: options.tz(),
            }),
    );

    const eventRangeLabel = computed(() => {
        const event = selectedEvent.value;

        if (!event) {
            return '';
        }

        const start = parseAbsolute(event.start, options.tz());
        const end = event.end ? parseAbsolute(event.end, options.tz()) : null;
        const startDate = dateFmt.value.format(start.toDate());

        if (event.allDay) {
            if (!end || dateKeyOf(end) === dateKeyOf(start)) {
                return startDate;
            }

            return `${startDate} – ${dateFmt.value.format(end.toDate())}`;
        }

        const startTime = timeFmt.value.format(start.toDate());

        if (!end) {
            return `${startDate} · ${startTime}`;
        }

        return `${startDate} · ${startTime} – ${timeFmt.value.format(end.toDate())}`;
    });

    // Defensive fallback: hand-built fixtures predating `actions` may omit it.
    const selectedEventActions = computed<KinetixAction[]>(
        () => selectedEvent.value?.actions ?? [],
    );

    return {
        selectedEvent,
        detailsOpen,
        selectedEventActions,
        eventRangeLabel,
        openEvent,
        closeDetails,
        eventActionClass,
        handleEventAction,
        pendingAction,
        isConfirmOpen,
        processing,
        onConfirmAction,
        onCancelAction,
    };
}
