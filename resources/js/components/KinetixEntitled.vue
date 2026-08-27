<script setup lang="ts">
import { computed } from 'vue';
import { useKinetixEntitlement } from '@/composables/useKinetixEntitlement';

/**
 * Declarative gate for a composed entitlement — one component where you would
 * otherwise nest `<KinetixFeature>` inside `<KinetixPlanFeature>` inside
 * `<KinetixCan>` and hope the three stay in sync with the controller.
 *
 *     <KinetixEntitled name="projects.create">
 *         <CreateProjectButton />
 *     </KinetixEntitled>
 *
 * The `denied` slot receives WHY, so one gate can hide, sell or refuse:
 *
 *     <KinetixEntitled name="projects.create">
 *         <template #default="{ remaining }">
 *             <CreateProjectButton />
 *             <span v-if="remaining !== null">{{ remaining }} left</span>
 *         </template>
 *         <template #denied="{ reason, isUpsell, remaining }">
 *             <KinetixPlanLock v-if="isUpsell" variant="badge" />
 *             <span v-else-if="reason === 'permission'">Read only</span>
 *         </template>
 *     </KinetixEntitled>
 *
 * With no `denied` slot nothing renders — the safe default: a feature behind a
 * flag should leave no trace, and an action a user can't take shouldn't hint
 * that it exists.
 *
 * Display gating only — the server still enforces every mutation.
 */
const props = withDefaults(
    defineProps<{
        /** The declared entitlement name, e.g. `projects.create`. */
        name?: string;
        /** Several names; ALL must allow (or ANY, with `require-any`). */
        names?: string[];
        /** Flip `names` from "all must allow" to "any may allow". */
        requireAny?: boolean;
    }>(),
    { name: undefined, names: undefined, requireAny: false },
);

const { allows, allowsAll, allowsAny, reason, isUpsell, remaining } =
    useKinetixEntitlement();

/** The name a denial reports on: the single `name`, else the first denied one. */
const subject = computed<string | null>(() => {
    if (props.name !== undefined) {
        return props.name;
    }

    if (props.names === undefined || props.names.length === 0) {
        return null;
    }

    return (
        props.names.find((entitlement) => !allows(entitlement)) ??
        props.names[0]
    );
});

const allowed = computed(() => {
    let ok = true;

    if (props.name !== undefined) {
        ok = ok && allows(props.name);
    }

    if (props.names !== undefined && props.names.length > 0) {
        ok =
            ok &&
            (props.requireAny
                ? allowsAny(props.names)
                : allowsAll(props.names));
    }

    return ok;
});

const slotProps = computed(() => ({
    reason: subject.value === null ? null : reason(subject.value),
    isUpsell: subject.value !== null && isUpsell(subject.value),
    remaining: subject.value === null ? null : remaining(subject.value),
}));
</script>

<template>
    <slot v-if="allowed" :remaining="slotProps.remaining" />
    <slot
        v-else
        name="denied"
        :reason="slotProps.reason"
        :is-upsell="slotProps.isUpsell"
        :remaining="slotProps.remaining"
    />
</template>
