<script setup lang="ts">
import { Check, ChevronsUpDown, X } from '@lucide/vue';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxLabel,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
} from 'reka-ui';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixTimezoneDisplay } from '@/types/kinetix';

/**
 * A searchable timezone combobox (built on the same Reka Combobox primitives
 * as `KinetixCombobox`) over every IANA zone the runtime supports
 * (`Intl.supportedValuesOf('timeZone')` — no bundled zone list to maintain).
 *
 * Rich, configurable: filter to specific regions, group the list by region,
 * choose what each option shows (city name / UTC offset / both — `display`
 * lets you drop the name entirely and show just the offset), and optionally
 * preview the live current time in the selected zone.
 */
const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        /**
         * Restrict the list to these IANA region prefixes (e.g.
         * `['America', 'Europe']`). `null` (default) allows every region.
         */
        regions?: string[] | null;
        /** BCP-47 locale for the current-time preview. Defaults to the browser locale. */
        locale?: string | null;
        /** What each option (and the trigger) shows. Default `both`. */
        display?: KinetixTimezoneDisplay;
        /** Group options under a region heading (Africa, America, …). Default `true`. */
        groupByRegion?: boolean;
        /** Show a live-updating current time next to the selected zone. */
        showCurrentTime?: boolean;
        placeholder?: string | null;
        disabled?: boolean;
        /** Show a clear (×) affordance once a zone is selected. */
        clearable?: boolean;
    }>(),
    {
        modelValue: null,
        regions: null,
        locale: null,
        display: 'both',
        groupByRegion: true,
        showCurrentTime: false,
        placeholder: null,
        disabled: false,
        clearable: false,
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | null): void;
}>();

const { t } = useI18n();
const localeValue = computed(() => props.locale ?? undefined);
const open = ref(false);

interface TimezoneOption {
    value: string;
    region: string;
    name: string;
    offset: string;
    offsetMinutes: number;
    display: string;
}

function offsetFor(tz: string): { label: string; minutes: number } {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: tz,
        timeZoneName: 'longOffset',
    }).formatToParts(new Date());
    const raw =
        parts.find((p) => p.type === 'timeZoneName')?.value ?? 'GMT+00:00';
    const label = raw.replace('GMT', 'UTC');
    const match = /UTC([+-])(\d{2}):(\d{2})/.exec(label);
    const minutes = match
        ? (match[1] === '-' ? -1 : 1) *
          (Number(match[2]) * 60 + Number(match[3]))
        : 0;

    return { label: label === 'UTC' ? 'UTC+00:00' : label, minutes };
}

function nameFor(tz: string): string {
    return tz.split('/').pop()!.replace(/_/g, ' ');
}

function renderLabel(name: string, offset: string): string {
    if (props.display === 'offset') {
        return offset;
    }

    if (props.display === 'name') {
        return name;
    }

    return `${name} (${offset})`;
}

const allZones = computed<TimezoneOption[]>(() => {
    const zones =
        typeof Intl.supportedValuesOf === 'function'
            ? Intl.supportedValuesOf('timeZone')
            : [];

    return zones
        .filter(
            (tz) => !props.regions || props.regions.includes(tz.split('/')[0]),
        )
        .map((tz): TimezoneOption => {
            const { label: offset, minutes } = offsetFor(tz);
            const name = nameFor(tz);

            return {
                value: tz,
                region: tz.split('/')[0],
                name,
                offset,
                offsetMinutes: minutes,
                display: renderLabel(name, offset),
            };
        })
        .sort(
            (a, b) =>
                a.offsetMinutes - b.offsetMinutes ||
                a.name.localeCompare(b.name),
        );
});

const regionLabel = (region: string): string =>
    t(`kinetix.timezone_region_${region.toLowerCase()}`, region);

const groups = computed(() => {
    if (!props.groupByRegion) {
        return [{ region: null as string | null, zones: allZones.value }];
    }

    const byRegion = new Map<string, TimezoneOption[]>();

    for (const zone of allZones.value) {
        const bucket = byRegion.get(zone.region);

        if (bucket) {
            bucket.push(zone);
        } else {
            byRegion.set(zone.region, [zone]);
        }
    }

    return [...byRegion.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([region, zones]) => ({ region, zones }));
});

const selected = computed(
    () => allZones.value.find((z) => z.value === props.modelValue) ?? null,
);

const now = ref(new Date());
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    if (props.showCurrentTime) {
        timer = setInterval(() => {
            now.value = new Date();
        }, 30_000);
    }
});
onBeforeUnmount(() => clearInterval(timer));

const currentTimeLabel = computed(() => {
    if (!props.showCurrentTime || !selected.value) {
        return null;
    }

    return new Intl.DateTimeFormat(localeValue.value, {
        timeZone: selected.value.value,
        hour: 'numeric',
        minute: '2-digit',
    }).format(now.value);
});

function onSelect(value: unknown): void {
    emit('update:modelValue', value ? String(value) : null);
    open.value = false;
}

function clear(event: Event): void {
    event.stopPropagation();
    emit('update:modelValue', null);
}

const triggerClass =
    'flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <ComboboxRoot
        :open="open"
        :model-value="modelValue ?? ''"
        :disabled="disabled"
        @update:open="open = $event"
        @update:model-value="onSelect"
    >
        <ComboboxAnchor as-child>
            <ComboboxTrigger :class="triggerClass">
                <span
                    class="min-w-0 gap-1.5 flex flex-1 items-center truncate"
                    :class="
                        selected ? 'text-foreground' : 'text-muted-foreground'
                    "
                >
                    <span class="truncate">{{
                        selected?.display ||
                        placeholder ||
                        t('kinetix.timezone_placeholder')
                    }}</span>
                    <span
                        v-if="currentTimeLabel"
                        class="text-xs shrink-0 text-muted-foreground tabular-nums"
                    >
                        · {{ currentTimeLabel }}
                    </span>
                </span>
                <button
                    v-if="clearable && selected"
                    type="button"
                    class="rounded-sm shrink-0 text-muted-foreground transition-colors hover:text-foreground"
                    :aria-label="t('kinetix.timezone_clear')"
                    @click="clear"
                >
                    <X class="size-3.5" />
                </button>
                <ChevronsUpDown class="size-4 shrink-0 opacity-50" />
            </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxPortal>
            <ComboboxContent
                position="popper"
                :side-offset="4"
                class="max-h-96 shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 relative z-[var(--kinetix-z-popover,120)] w-(--reka-combobox-trigger-width) min-w-[16rem] origin-(--reka-combobox-content-transform-origin) overflow-hidden rounded-md border border-border bg-popover text-popover-foreground"
            >
                <div class="px-3 flex items-center border-b border-border">
                    <ComboboxInput
                        :placeholder="t('kinetix.timezone_search_placeholder')"
                        :display-value="
                            (value) =>
                                allZones.find((z) => z.value === value)
                                    ?.display ?? ''
                        "
                        class="h-9 text-sm w-full bg-transparent text-foreground outline-none placeholder:text-muted-foreground"
                    />
                </div>

                <ComboboxEmpty
                    class="py-6 text-sm text-center text-muted-foreground"
                >
                    {{ t('kinetix.timezone_empty') }}
                </ComboboxEmpty>

                <ComboboxViewport class="max-h-72 p-1 overflow-y-auto">
                    <template
                        v-for="group in groups"
                        :key="group.region ?? 'all'"
                    >
                        <ComboboxGroup>
                            <ComboboxLabel
                                v-if="group.region"
                                class="px-2 py-1.5 text-xs font-medium text-muted-foreground"
                            >
                                {{ regionLabel(group.region) }}
                            </ComboboxLabel>
                            <ComboboxItem
                                v-for="zone in group.zones"
                                :key="zone.value"
                                :value="zone.value"
                                class="gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm relative flex w-full cursor-default items-center text-foreground outline-none select-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
                            >
                                <span class="truncate">{{ zone.display }}</span>
                                <span
                                    class="right-2 size-3.5 absolute flex items-center justify-center"
                                >
                                    <ComboboxItemIndicator>
                                        <Check class="size-4" />
                                    </ComboboxItemIndicator>
                                </span>
                            </ComboboxItem>
                        </ComboboxGroup>
                    </template>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>
</template>
