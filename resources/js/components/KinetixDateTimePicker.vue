<script setup lang="ts">
import { CalendarIcon } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import {
    useKinetixTimezone,
    zonedNow,
    zonedTodayIso,
} from '@/composables/useKinetixTimezone';
import KinetixButton from './KinetixButton.vue';
import KinetixCalendar from './KinetixCalendar.vue';
import { cn } from './primitives/cn';
import ScrollArea from './primitives/ScrollArea.vue';

/**
 * Date + time picker: calendar plus scrollable hour/minute (+ AM/PM) columns.
 * Picking a date deliberately does NOT close the popover (unlike the pure
 * DatePicker) — the user still has a time to choose; the footer's **Done** is
 * the explicit dismissal, and **Now** jumps to the current date + time
 * (rounded to `minuteStep`). With `confirm`, every click builds a DRAFT,
 * Done becomes **Apply** (the only thing that emits), and dismissing any
 * other way discards the draft.
 */
const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        /** Selected value as an ISO 'Y-m-dTH:i' string (always 24h storage). */
        value?: string | null;
        /** Render a plain native <input type="datetime-local"> instead. */
        native?: boolean;
        /** Use a 12-hour clock with an AM/PM column instead of 24-hour. */
        hour12?: boolean;
        disabled?: boolean;
        /** Overrides the default `kinetix.datetime_placeholder`. */
        placeholder?: string | null;
        locale?: string | null;
        minuteStep?: number;
        /** Commit only on Apply; outside-click/Escape discards the draft. */
        confirm?: boolean;
        /**
         * IANA timezone the Now preset (and the calendar's initial month)
         * reads the clock in. Defaults to the app timezone Kinetix shares
         * (`config('app.timezone')`), then the browser clock.
         */
        timezone?: string | null;
    }>(),
    {
        value: null,
        native: false,
        hour12: false,
        disabled: false,
        placeholder: null,
        locale: null,
        minuteStep: 5,
        confirm: false,
        timezone: null,
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: string | null): void;
}>();

const open = ref(false);
const pad = (n: number) => String(n).padStart(2, '0');
const effectiveTimezone = useKinetixTimezone(() => props.timezone);

// Local selection: what the popover shows/edits. In live mode every change
// also emits; in confirm mode only Apply does.
const selDate = ref<string | null>(null);
const selHour = ref(0); // 24h
const selMinute = ref(0);

const seedFromValue = () => {
    selDate.value = props.value ? props.value.slice(0, 10) : null;
    selHour.value = Number(props.value?.slice(11, 13) ?? '0') || 0;
    selMinute.value = Number(props.value?.slice(14, 16) ?? '0') || 0;
};

const isPm = computed(() => selHour.value >= 12);
const displayHour = computed(() => selHour.value % 12 || 12);

const hours = computed(() =>
    props.hour12
        ? Array.from({ length: 12 }, (_, i) => i + 1)
        : Array.from({ length: 24 }, (_, i) => i),
);
const minutes = computed(() =>
    Array.from(
        { length: Math.ceil(60 / props.minuteStep) },
        (_, i) => i * props.minuteStep,
    ),
);

const formatted = computed(() => {
    if (!props.value) {
        return null;
    }

    const [y, m, d] = props.value.slice(0, 10).split('-').map(Number);
    const h = Number(props.value.slice(11, 13)) || 0;
    const min = Number(props.value.slice(14, 16)) || 0;

    return new Date(y, m - 1, d, h, min).toLocaleString(
        props.locale || undefined,
        {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: props.hour12,
        },
    );
});

const todayIso = () => zonedTodayIso(effectiveTimezone.value);

const commit = () =>
    emit(
        'update:value',
        `${selDate.value ?? todayIso()}T${pad(selHour.value)}:${pad(selMinute.value)}`,
    );

const select = (date: string | null, h: number, m: number) => {
    selDate.value = date ?? todayIso();
    selHour.value = h;
    selMinute.value = m;

    if (!props.confirm) {
        commit();
    }
};

const onDateSelect = (date: string | null) => {
    if (date) {
        select(date, selHour.value, selMinute.value);
    }
};

const setHour24 = (h: number) => select(selDate.value, h, selMinute.value);

// In 12h mode the clicked hour is 1-12; map it to 24h keeping the AM/PM half.
const setHour12 = (h: number) =>
    select(selDate.value, (h % 12) + (isPm.value ? 12 : 0), selMinute.value);

const setMinute = (m: number) => select(selDate.value, selHour.value, m);

const setMeridiem = (meridiem: 'AM' | 'PM') => {
    const h = selHour.value;
    const next =
        meridiem === 'PM' && h < 12
            ? h + 12
            : meridiem === 'AM' && h >= 12
              ? h - 12
              : h;

    select(selDate.value, next, selMinute.value);
};

/**
 * Current date + time in the effective timezone (app timezone by default),
 * rounded to the nearest `minuteStep`.
 */
const setNow = () => {
    const now = zonedNow(effectiveTimezone.value);
    let m = Math.round(now.minute / props.minuteStep) * props.minuteStep;
    let h = now.hour;
    let date = `${now.year}-${pad(now.month)}-${pad(now.day)}`;

    if (m >= 60) {
        m = 0;
        h = h + 1;

        if (h >= 24) {
            // Rounding past midnight rolls the DATE too, not just the hour.
            h = 0;
            const next = new Date(now.year, now.month - 1, now.day + 1);
            date = `${next.getFullYear()}-${pad(next.getMonth() + 1)}-${pad(next.getDate())}`;
        }
    }

    select(date, h, m);
    nextTick(() => {
        centerInScrollParent(hourEl.value);
        centerInScrollParent(minuteEl.value);
    });
};

/** Done in live mode just dismisses; Apply in confirm mode is THE commit. */
const done = () => {
    if (props.confirm && selDate.value !== null) {
        commit();
    }

    open.value = false;
};

// Mobile-first hit areas: ~40px tall touch rows (44px incl. gap), compact
// squares from `sm:` up. touch-manipulation kills the 300ms tap delay.
const timeBtn = (active: boolean) =>
    cn(
        buttonVariants({
            variant: active ? 'default' : 'ghost',
            size: 'icon-sm',
        }),
        'h-10 w-12 sm:h-8 sm:w-full shrink-0 touch-manipulation',
    );

// Reveal the selected hour/minute in their scroll columns when the popover opens.
const hourEl = ref<HTMLElement | null>(null);
const minuteEl = ref<HTMLElement | null>(null);

const hourRef = (el: unknown, h: number) => {
    const active = props.hour12 ? displayHour.value === h : selHour.value === h;

    if (active && el) {
        hourEl.value = el as HTMLElement;
    }
};
const minuteRef = (el: unknown, m: number) => {
    if (selMinute.value === m && el) {
        minuteEl.value = el as HTMLElement;
    }
};

/** Scroll only the nearest scrollable ancestor (not the page) to center `el`. */
const centerInScrollParent = (el: HTMLElement | null) => {
    if (!el) {
        return;
    }

    let parent = el.parentElement;

    while (parent && parent.scrollHeight <= parent.clientHeight) {
        parent = parent.parentElement;
    }

    if (!parent) {
        return;
    }

    const elRect = el.getBoundingClientRect();
    const pRect = parent.getBoundingClientRect();
    parent.scrollTop +=
        elRect.top - pRect.top - pRect.height / 2 + elRect.height / 2;
};

watch(open, (isOpen) => {
    if (isOpen) {
        // (Re)seed the draft from the committed value — an abandoned confirm
        // draft must not leak into the next opening.
        seedFromValue();

        if (selDate.value != null) {
            nextTick(() => {
                centerInScrollParent(hourEl.value);
                centerInScrollParent(minuteEl.value);
            });
        }
    }
});
</script>

<template>
    <input
        v-if="native"
        type="datetime-local"
        :value="value"
        :disabled="disabled"
        @input="
            emit(
                'update:value',
                ($event.target as HTMLInputElement).value || null,
            )
        "
    />

    <PopoverRoot v-else v-model:open="open">
        <PopoverTrigger
            :disabled="disabled"
            :class="
                cn(
                    buttonVariants({ variant: 'outline' }),
                    'font-normal w-full touch-manipulation justify-start text-left',
                    !value && 'text-muted-foreground',
                )
            "
        >
            <CalendarIcon class="mr-2 h-4 w-4" />
            {{ formatted ?? placeholder ?? t('kinetix.datetime_placeholder') }}
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                class="p-0 shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] w-auto rounded-md border border-border bg-popover outline-none"
            >
                <!-- Stacked on mobile the panel can exceed the viewport, so it
                     caps at 80dvh and scrolls internally instead of clipping. -->
                <div
                    class="sm:max-h-none sm:overflow-visible max-h-[min(80dvh,560px)] overflow-y-auto"
                >
                    <div class="sm:flex">
                        <KinetixCalendar
                            class="border-0"
                            :value="selDate"
                            :timezone="timezone"
                            :locale="locale"
                            @update:value="onDateSelect"
                        />
                        <div
                            class="sm:border-t-0 sm:border-l flex h-[260px] divide-x divide-border border-t border-border"
                        >
                            <!-- Hours -->
                            <ScrollArea
                                class="h-full"
                                type="always"
                                :aria-label="t('kinetix.picker_hours')"
                            >
                                <div class="gap-1 p-2 flex flex-col">
                                    <button
                                        v-for="h in hours"
                                        :key="`h-${h}`"
                                        :ref="(el) => hourRef(el, h)"
                                        type="button"
                                        :aria-pressed="
                                            selDate != null &&
                                            (hour12
                                                ? displayHour === h
                                                : selHour === h)
                                        "
                                        :class="
                                            timeBtn(
                                                selDate != null &&
                                                    (hour12
                                                        ? displayHour === h
                                                        : selHour === h),
                                            )
                                        "
                                        @click="
                                            hour12 ? setHour12(h) : setHour24(h)
                                        "
                                    >
                                        {{ pad(h) }}
                                    </button>
                                </div>
                            </ScrollArea>
                            <!-- Minutes -->
                            <ScrollArea
                                class="h-full"
                                type="always"
                                :aria-label="t('kinetix.picker_minutes')"
                            >
                                <div class="gap-1 p-2 flex flex-col">
                                    <button
                                        v-for="m in minutes"
                                        :key="`m-${m}`"
                                        :ref="(el) => minuteRef(el, m)"
                                        type="button"
                                        :aria-pressed="
                                            selDate != null && selMinute === m
                                        "
                                        :class="
                                            timeBtn(
                                                selDate != null &&
                                                    selMinute === m,
                                            )
                                        "
                                        @click="setMinute(m)"
                                    >
                                        {{ pad(m) }}
                                    </button>
                                </div>
                            </ScrollArea>
                            <!-- AM/PM (12h only) -->
                            <ScrollArea v-if="hour12" class="h-full">
                                <div class="gap-1 p-2 flex flex-col">
                                    <button
                                        v-for="meridiem in ['AM', 'PM']"
                                        :key="meridiem"
                                        type="button"
                                        :aria-pressed="
                                            selDate != null &&
                                            ((meridiem === 'AM' && !isPm) ||
                                                (meridiem === 'PM' && isPm))
                                        "
                                        :class="
                                            timeBtn(
                                                selDate != null &&
                                                    ((meridiem === 'AM' &&
                                                        !isPm) ||
                                                        (meridiem === 'PM' &&
                                                            isPm)),
                                            )
                                        "
                                        @click="
                                            setMeridiem(meridiem as 'AM' | 'PM')
                                        "
                                    >
                                        {{ meridiem }}
                                    </button>
                                </div>
                            </ScrollArea>
                        </div>
                    </div>
                </div>

                <!-- Footer: Now preset + explicit dismiss/commit -->
                <div
                    class="gap-2 p-2 flex items-center justify-between border-t border-border"
                >
                    <KinetixButton variant="ghost" size="sm" @click="setNow">
                        {{ t('kinetix.picker_now') }}
                    </KinetixButton>
                    <KinetixButton size="sm" @click="done">
                        {{
                            confirm
                                ? t('kinetix.apply')
                                : t('kinetix.picker_done')
                        }}
                    </KinetixButton>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
