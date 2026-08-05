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
import KinetixCalendar from './KinetixCalendar.vue';
import { cn } from './primitives/cn';
import ScrollArea from './primitives/ScrollArea.vue';

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
    }>(),
    {
        value: null,
        native: false,
        hour12: false,
        disabled: false,
        placeholder: null,
        locale: null,
        minuteStep: 5,
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: string | null): void;
}>();

const open = ref(false);
const pad = (n: number) => String(n).padStart(2, '0');

const datePart = computed(() =>
    props.value ? props.value.slice(0, 10) : null,
);
const hour24 = computed(() => Number(props.value?.slice(11, 13) ?? '0') || 0);
const minute = computed(() => Number(props.value?.slice(14, 16) ?? '0') || 0);
const isPm = computed(() => hour24.value >= 12);
const displayHour = computed(() => hour24.value % 12 || 12);

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
    if (!props.value || !datePart.value) {
        return props.value;
    }

    const [y, m, d] = datePart.value.split('-').map(Number);

    return new Date(y, m - 1, d, hour24.value, minute.value).toLocaleString(
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

const todayIso = () => {
    const now = new Date();

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

const emitParts = (date: string, h: number, m: number) =>
    emit('update:value', `${date}T${pad(h)}:${pad(m)}`);

const onDateSelect = (date: string | null) => {
    if (date) {
        emitParts(date, hour24.value, minute.value);
    }
};

const setHour24 = (h: number) =>
    emitParts(datePart.value ?? todayIso(), h, minute.value);

// In 12h mode the clicked hour is 1-12; map it to 24h keeping the AM/PM half.
const setHour12 = (h: number) =>
    emitParts(
        datePart.value ?? todayIso(),
        (h % 12) + (isPm.value ? 12 : 0),
        minute.value,
    );

const setMinute = (m: number) =>
    emitParts(datePart.value ?? todayIso(), hour24.value, m);

const setMeridiem = (meridiem: 'AM' | 'PM') => {
    const h = hour24.value;
    const next =
        meridiem === 'PM' && h < 12
            ? h + 12
            : meridiem === 'AM' && h >= 12
              ? h - 12
              : h;

    emitParts(datePart.value ?? todayIso(), next, minute.value);
};

const timeBtn = (active: boolean) =>
    cn(
        buttonVariants({
            variant: active ? 'default' : 'ghost',
            size: 'icon-sm',
        }),
        'sm:w-full aspect-square shrink-0',
    );

// Reveal the selected hour/minute in their scroll columns when the popover opens.
const hourEl = ref<HTMLElement | null>(null);
const minuteEl = ref<HTMLElement | null>(null);

const hourRef = (el: unknown, h: number) => {
    const active = props.hour12 ? displayHour.value === h : hour24.value === h;

    if (active && el) {
        hourEl.value = el as HTMLElement;
    }
};
const minuteRef = (el: unknown, m: number) => {
    if (minute.value === m && el) {
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
    if (isOpen && datePart.value != null) {
        nextTick(() => {
            centerInScrollParent(hourEl.value);
            centerInScrollParent(minuteEl.value);
        });
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
                    'font-normal w-full justify-start text-left',
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
                class="p-0 shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 z-[var(--kinetix-z-popover,120)] w-auto rounded-md border border-border bg-popover outline-none"
            >
                <div class="sm:flex">
                    <KinetixCalendar
                        class="border-0"
                        :value="datePart"
                        :locale="locale"
                        @update:value="onDateSelect"
                    />
                    <div
                        class="sm:border-t-0 sm:border-l flex h-[260px] divide-x divide-border border-t border-border"
                    >
                        <!-- Hours -->
                        <ScrollArea class="h-full" type="always">
                            <div class="gap-1 p-2 flex flex-col">
                                <button
                                    v-for="h in hours"
                                    :key="`h-${h}`"
                                    :ref="(el) => hourRef(el, h)"
                                    type="button"
                                    :class="
                                        timeBtn(
                                            datePart != null &&
                                                (hour12
                                                    ? displayHour === h
                                                    : hour24 === h),
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
                        <ScrollArea class="h-full" type="always">
                            <div class="gap-1 p-2 flex flex-col">
                                <button
                                    v-for="m in minutes"
                                    :key="`m-${m}`"
                                    :ref="(el) => minuteRef(el, m)"
                                    type="button"
                                    :class="
                                        timeBtn(
                                            datePart != null && minute === m,
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
                                    :class="
                                        timeBtn(
                                            datePart != null &&
                                                ((meridiem === 'AM' && !isPm) ||
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
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
