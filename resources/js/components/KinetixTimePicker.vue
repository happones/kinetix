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
import { cn } from './primitives/cn';
import ScrollArea from './primitives/ScrollArea.vue';

/**
 * Time-only picker. Renders an input-style trigger that opens a popover with
 * scrollable hour/minute (+ AM/PM) columns, or a native <input type="time">
 * when `native`. Value is a 24-hour 'H:i' string (e.g. "14:30"). Defaults to a
 * 12-hour clock with AM/PM — pass `:hour12="false"` for 24-hour.
 */
const props = withDefaults(
    defineProps<{
        value?: string | null;
        native?: boolean;
        hour12?: boolean;
        disabled?: boolean;
        placeholder?: string | null;
        minuteStep?: number;
    }>(),
    {
        value: null,
        native: false,
        hour12: true,
        disabled: false,
        placeholder: null,
        minuteStep: 5,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

const { t } = useI18n();
const open = ref(false);
const pad = (n: number) => String(n).padStart(2, '0');

const hasValue = computed(() => props.value != null && props.value !== '');
const hour24 = computed(() => Number(props.value?.slice(0, 2) ?? '0') || 0);
const minute = computed(() => Number(props.value?.slice(3, 5) ?? '0') || 0);
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
    if (!hasValue.value) {
        return null;
    }

    return props.hour12
        ? `${pad(displayHour.value)}:${pad(minute.value)} ${isPm.value ? 'PM' : 'AM'}`
        : `${pad(hour24.value)}:${pad(minute.value)}`;
});

const emitTime = (h: number, m: number) =>
    emit('update:value', `${pad(h)}:${pad(m)}`);
const setHour24 = (h: number) => emitTime(h, minute.value);
const setHour12 = (h: number) =>
    emitTime(isPm.value ? (h % 12) + 12 : h % 12, minute.value);
const setMinute = (m: number) => emitTime(hour24.value, m);
const setMeridiem = (meridiem: 'AM' | 'PM') => {
    const h = hour24.value;
    const next =
        meridiem === 'PM' && h < 12
            ? h + 12
            : meridiem === 'AM' && h >= 12
              ? h - 12
              : h;
    emitTime(next, minute.value);
};

const timeBtn = (active: boolean) =>
    cn(
        buttonVariants({
            variant: active ? 'default' : 'ghost',
            size: 'icon-sm',
        }),
        'sm:w-full aspect-square shrink-0',
    );

// Reveal the selected hour/minute when the popover opens.
const hourEl = ref<HTMLElement | null>(null);
const minuteEl = ref<HTMLElement | null>(null);
const hourRef = (el: unknown, h: number) => {
    if ((props.hour12 ? displayHour.value === h : hour24.value === h) && el) {
        hourEl.value = el as HTMLElement;
    }
};
const minuteRef = (el: unknown, m: number) => {
    if (minute.value === m && el) {
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
    if (isOpen && hasValue.value) {
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
                    'font-normal w-full justify-start text-left',
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
                    <ScrollArea class="h-full" type="always">
                        <div class="gap-1 p-2 flex flex-col">
                            <button
                                v-for="h in hours"
                                :key="`h-${h}`"
                                :ref="(el) => hourRef(el, h)"
                                type="button"
                                :class="
                                    timeBtn(
                                        hasValue &&
                                            (hour12
                                                ? displayHour === h
                                                : hour24 === h),
                                    )
                                "
                                @click="hour12 ? setHour12(h) : setHour24(h)"
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
                                :class="timeBtn(hasValue && minute === m)"
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
                                        hasValue &&
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
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
