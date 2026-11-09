<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixRecordReference } from '@/types/kinetix';
import KinetixCopyable from './primitives/KinetixCopyable.vue';

/**
 * A record's discreet reference ("Order · …7KQZ"): enough of a global id
 * (ULID, UUID, slug) for support to recognise, without filling the page with
 * it. One click copies the full text (`reference.copy`), e.g. "Order: 01JD…",
 * so the record can be found exactly. Renders nothing without a reference.
 */
const props = defineProps<{
    reference?: KinetixRecordReference | null;
}>();

const { t } = useI18n();

const spoken = computed<string>(() => {
    const reference = props.reference;

    if (!reference) {
        return '';
    }

    const key = {
        start: 'kinetix.record_reference_start',
        end: 'kinetix.record_reference_end',
        full: 'kinetix.record_reference_full',
    }[reference.position];

    return t(key, {
        label: reference.label ?? t('kinetix.record_reference_record'),
        part: reference.visible,
    });
});
</script>

<template>
    <KinetixCopyable
        v-if="reference"
        :value="reference.copy"
        data-kinetix-record-reference
        class="min-h-6 text-xs text-muted-foreground"
    >
        <span class="sr-only">{{ spoken }}</span>
        <span
            class="gap-x-1 min-w-0 inline-flex flex-wrap items-center"
            aria-hidden="true"
        >
            <span v-if="reference.label" class="whitespace-nowrap">
                {{ reference.label }}
            </span>
            <span v-if="reference.label" class="text-muted-foreground/60"
                >·</span
            >
            <span class="font-mono tracking-wide break-all tabular-nums"
                >{{ reference.position === 'end' ? '…' : ''
                }}{{ reference.visible
                }}{{ reference.position === 'start' ? '…' : '' }}</span
            >
        </span>
    </KinetixCopyable>
</template>
