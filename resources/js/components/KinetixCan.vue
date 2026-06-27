<script setup lang="ts">
import { computed } from 'vue';
import { useKinetixCan } from '@/composables/useKinetixCan';

/**
 * Declarative permission/role gate. Renders the default slot only when the check
 * passes; otherwise renders the optional `denied` slot. Reactive to page props.
 *
 *   <KinetixCan permission="posts.update"><EditButton /></KinetixCan>
 *   <KinetixCan :permission="['posts.create','posts.update']">…</KinetixCan>  // any
 *   <KinetixCan :permission="[...]" require-all>…</KinetixCan>                // all
 *   <KinetixCan role="admin">…</KinetixCan>
 */
const props = withDefaults(
    defineProps<{
        permission?: string | string[];
        role?: string | string[];
        requireAll?: boolean;
    }>(),
    { permission: undefined, role: undefined, requireAll: false },
);

const { canAny, canAll, hasRole } = useKinetixCan();

const allowed = computed(() => {
    let ok = true;

    if (props.permission !== undefined) {
        const perms = Array.isArray(props.permission)
            ? props.permission
            : [props.permission];
        ok = ok && (props.requireAll ? canAll(perms) : canAny(perms));
    }

    if (props.role !== undefined) {
        ok = ok && hasRole(props.role);
    }

    return ok;
});
</script>

<template>
    <slot v-if="allowed" />
    <slot v-else name="denied" />
</template>
