<script setup lang="ts">
import { Clock } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import { useKinetixTimezone, zonedNow } from '@/composables/useKinetixTimezone';
import KinetixButton from './KinetixButton.vue';
import { cn } from './primitives/cn';
import ScrollArea from './primitives/ScrollArea.vue';

/**
 * Time-only picker. Renders an input-style trigger that opens a popover with
 * scrollable hour/minute (+ AM/PM) columns, or a native <input type="time">
 * when `native`. Value is a 24-hour 'H:i' string (e.g. "14:30"). Defaults to a
 * 12-hour clock with AM/PM — pass `:hour12="false"` for 24-hour.
 *
 * A footer offers **Now** (current time, rounded to `minuteStep`) and **Done**
 * (explicit way to dismiss — a multi-part picker should never rely on
 * outside-click alone). With `confirm`, clicks build a DRAFT instead of
 * committing live, Done becomes **Apply** (the only thing that emits), and
 * dismissing any other way discards the draft.
 */
const props = withDefaults(
    defineProps<{
        value?: string | null;
        native?: boolean;
        hour12?: boolean;
        disabled?: boolean;
        placeholder?: string | null;
        minuteStep?: number;
        /** Commit only on Apply; outside-click/Escape discards the draft. */
        confirm?: boolean;
        /**
         * IANA timezone the Now preset reads the clock in. Defaults to the
         * app timezone Kinetix shares (`config('app.timezone')`), falling
         * back to the browser clock outside an Inertia app.
         */
        timezone?: string | null;
    }>(),
    {
        value: null,
        native: false,
        hour12: true,
        disabled: false,
        placeholder: null,
        minuteStep: 5,
        confirm: false,
        timezone: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

const { t } = useI18n();
const open = ref(false);
const pad = (n: number) => String(n).padStart(2, '0');
const effectiveTimezone = useKinetixTimezone(() => props.timezone);

const hasValue = computed(() => props.value != null && props.value !== '');

// Local selection: what the popover shows/edits. In live mode every change
// also emits; in confirm mode only Apply does.
const selHour = ref(0); // 24h
const selMinute = ref(0);
const touched = ref(false);

const seedFromValue = () => {
    selHour.value = Number(props.value?.slice(0, 2) ?? '0') || 0;
    selMinute.value = Number(props.value?.slice(3, 5) ?? '0') || 0;
    touched.value = hasValue.value;
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
    if (!hasValue.value) {
        return null;
    }

    const h = Number(props.value?.slice(0, 2) ?? '0') || 0;
    const m = Number(props.value?.slice(3, 5) ?? '0') || 0;

    return props.hour12
        ? `${pad(h % 12 || 12)}:${pad(m)} ${h >= 12 ? 'PM' : 'AM'}`
        : `${pad(h)}:${pad(m)}`;
});

const commit = () =>
    emit('update:value', `${pad(selHour.value)}:${pad(selMinute.value)}`);

const select = (h: number, m: number) => {
    selHour.value = h;
    selMinute.value = m;
    touched.value = true;

    if (!props.confirm) {
        commit();
    }
};

const setHour24 = (h: number) => select(h, selMinute.value);
const setHour12 = (h: number) =>
    select(isPm.value ? (h % 12) + 12 : h % 12, selMinute.value);
const setMinute = (m: number) => select(selHour.value, m);
const setMeridiem = (meridiem: 'AM' | 'PM') => {
    const h = selHour.value;
    const next =
        meridiem === 'PM' && h < 12
            ? h + 12
            : meridiem === 'AM' && h >= 12
              ? h - 12
              : h;
    select(next, selMinute.value);
};

/**
 * Current time in the effective timezone (app timezone by default), rounded
 * to the nearest `minuteStep` (carrying into the hour).
 */
const nowParts = (): { h: number; m: number } => {
    const now = zonedNow(effectiveTimezone.value);
    let m = Math.round(now.minute / props.minuteStep) * props.minuteStep;
    let h = now.hour;

    if (m >= 60) {
        m = 0;
        h = (h + 1) % 24;
    }

    return { h, m };
};

const setNow = () => {
    const { h, m } = nowParts();
    select(h, m);
    nextTick(() => {
        centerInScrollParent(hourEl.value);
        centerInScrollParent(minuteEl.value);
    });
};

/** Done in live mode just dismisses; Apply in confirm mode is THE commit. */
const done = () => {
    if (props.confirm && touched.value) {
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

// Reveal the selected hour/minute when the popover opens.
const hourEl = ref<HTMLElement | null>(null);
const minuteEl = ref<HTMLElement | null>(null);
const hourRef = (el: unknown, h: number) => {
    if ((props.hour12 ? displayHour.value === h : selHour.value === h) && el) {
        hourEl.value = el as HTMLElement;
    }
};
const minuteRef = (el: unknown, m: number) => {
    if (selMinute.value === m && el) {
        minuteEl.value = el as HTMLElement;
    }
};
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

        if (hasValue.value) {
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
        type="time"
        :value="value"
        :disabled="disabled"
        :class="inputClass"
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
                    !hasValue && 'text-muted-foreground',
                )
            "
        >
            <Clock class="mr-2 h-4 w-4" />
            {{ formatted ?? placeholder ?? t('kinetix.pick_time') }}
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                class="p-0 shadow-md z-[var(--kinetix-z-popover,120)] w-auto rounded-md border border-border bg-popover outline-none"
            >
                <div class="flex h-[220px] divide-x divide-border">
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
                                    touched &&
                                    (hour12 ? displayHour === h : selHour === h)
                                "
                                :class="
                                    timeBtn(
                                        touched &&
                                            (hour12
                                                ? displayHour === h
                                                : selHour === h),
                                    )
                                "
                                @click="hour12 ? setHour12(h) : setHour24(h)"
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
                                :aria-pressed="touched && selMinute === m"
                                :class="timeBtn(touched && selMinute === m)"
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
                                    touched &&
                                    ((meridiem === 'AM' && !isPm) ||
                                        (meridiem === 'PM' && isPm))
                                "
                                :class="
                                    timeBtn(
                                        touched &&
                                            ((meridiem === 'AM' && !isPm) ||
                                                (meridiem === 'PM' && isPm)),
                                    )
                                "
                                @click="setMeridiem(meridiem as 'AM' | 'PM')"
                            >
                                {{ meridiem }}
                            </button>
                        </div>
                    </ScrollArea>
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
