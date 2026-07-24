<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import type { KinetixPermissionFeature, KinetixRole } from '@/types';
import KinetixLabel from '../KinetixLabel.vue';

/**
 * The role editor dialog shared by `KinetixRoleMatrix` and
 * `KinetixRolesOverview`: a name field plus a module × ability matrix. The
 * matrix scrolls inside its own container with a sticky header row AND a
 * sticky module column, so both the ability being granted and the module it
 * belongs to stay visible while scrolling a long catalog. The name field and
 * the Save/Cancel footer never scroll away.
 *
 * `role` is the role being edited (`null` opens an empty create draft); the
 * draft is (re)seeded every time the dialog opens, and `save` emits the draft
 * without mutating the original role.
 */
const props = defineProps<{
    open: boolean;
    role: KinetixRole | null;
    features: KinetixPermissionFeature[];
    saving?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'save', role: KinetixRole): void;
}>();

const { t } = useI18n();

/** Canonical CRUD columns first; any custom abilities append after. */
const CANONICAL_ORDER = [
    'viewAny',
    'view',
    'create',
    'update',
    'delete',
    'deleteAny',
    'restore',
    'forceDelete',
];

/**
 * Column headers for the canonical keys. The columns are SHARED across every
 * feature, so a feature's own ability label (e.g. members' "Change member
 * role" for `update`) must never become the header — canonical keys always
 * render the generic translation; only custom abilities keep their label.
 */
const CANONICAL_LABELS: Record<string, string> = {
    viewAny: 'kinetix.view_any',
    view: 'kinetix.view',
    create: 'kinetix.create',
    update: 'kinetix.edit',
    delete: 'kinetix.delete',
    deleteAny: 'kinetix.delete_any',
    restore: 'kinetix.restore',
    forceDelete: 'kinetix.force_delete',
};

/** Distinct ability columns across the catalog, canonically ordered. */
const abilityColumns = computed<{ key: string; label: string }[]>(() => {
    const seen = new Map<string, string>();

    for (const feature of props.features) {
        for (const ability of feature.abilities) {
            if (!seen.has(ability.key)) {
                const translation = CANONICAL_LABELS[ability.key];
                seen.set(
                    ability.key,
                    translation ? t(translation) : ability.label,
                );
            }
        }
    }

    return [...seen.entries()]
        .sort(([a], [b]) => {
            const ai = CANONICAL_ORDER.indexOf(a);
            const bi = CANONICAL_ORDER.indexOf(b);

            return (
                (ai === -1 ? CANONICAL_ORDER.length : ai) -
                (bi === -1 ? CANONICAL_ORDER.length : bi)
            );
        })
        .map(([key, label]) => ({ key, label }));
});

// feature name → ability key → permission string, built once so the matrix's
// per-cell lookup is O(1) instead of a `.find()` over each feature's abilities.
const permissionIndex = computed<Map<string, Map<string, string>>>(() => {
    const index = new Map<string, Map<string, string>>();

    for (const feature of props.features) {
        const abilities = new Map<string, string>();

        for (const ability of feature.abilities) {
            abilities.set(ability.key, ability.permission);
        }

        index.set(feature.name, abilities);
    }

    return index;
});

/** feature name → ability key → permission string (null when not declared). */
const permissionFor = (
    feature: KinetixPermissionFeature,
    abilityKey: string,
): string | null =>
    permissionIndex.value.get(feature.name)?.get(abilityKey) ?? null;

// --- Draft state ------------------------------------------------------------

const draftName = ref('');
const draftPermissions = ref<string[]>([]);

watch(
    () => props.open,
    (open) => {
        if (open) {
            draftName.value = props.role?.name ?? '';
            draftPermissions.value = [...(props.role?.permissions ?? [])];
        }
    },
);

// Draft permissions mirrored as a Set so the per-cell `has()` check is O(1).
// The matrix calls it for every (feature × ability) cell on each render/toggle,
// which was an O(cells × permissions) scan on the plain array.
const draftSet = computed<Set<string>>(() => new Set(draftPermissions.value));

const has = (permission: string | null): boolean =>
    permission !== null && draftSet.value.has(permission);

function toggle(permission: string | null): void {
    if (!permission) {
        return;
    }

    draftPermissions.value = has(permission)
        ? draftPermissions.value.filter((name) => name !== permission)
        : [...draftPermissions.value, permission];
}

/** Toggle every ability a feature declares, on/off at once. */
function toggleRow(feature: KinetixPermissionFeature): void {
    const names = feature.abilities.map((a) => a.permission);
    const allOn = names.every((name) => draftSet.value.has(name));
    const nameSet = new Set(names);

    draftPermissions.value = allOn
        ? draftPermissions.value.filter((name) => !nameSet.has(name))
        : [...new Set([...draftPermissions.value, ...names])];
}

function submit(): void {
    if (!draftName.value.trim()) {
        return;
    }

    emit('save', {
        id: props.role?.id ?? null,
        name: draftName.value.trim(),
        permissions: draftPermissions.value,
    });
}
</script>

<template>
    <DialogRoot
        :open="open"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <DialogPortal>
            <DialogOverlay
                class="inset-0 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed z-50"
            />
            <DialogContent
                class="max-w-2xl rounded-xl p-6 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-1/2 left-1/2 z-50 flex max-h-[90vh] w-[92vw] -translate-x-1/2 -translate-y-1/2 flex-col border border-border bg-card text-card-foreground outline-none"
            >
                <form
                    class="gap-4 min-h-0 flex flex-1 flex-col"
                    @submit.prevent="submit"
                >
                    <div class="flex items-start justify-between">
                        <div>
                            <DialogTitle
                                class="text-lg font-semibold tracking-tight leading-none"
                            >
                                {{
                                    role
                                        ? t('kinetix.edit')
                                        : t('kinetix.create_role')
                                }}
                            </DialogTitle>
                            <DialogDescription
                                class="mt-1.5 text-sm text-muted-foreground"
                            >
                                {{ t('kinetix.role_matrix_hint') }}
                            </DialogDescription>
                        </div>
                        <DialogClose
                            :class="
                                buttonVariants({
                                    variant: 'ghost',
                                    size: 'icon-sm',
                                })
                            "
                        >
                            <X class="h-4 w-4" />
                        </DialogClose>
                    </div>

                    <div class="space-y-2">
                        <KinetixLabel for="kx-role-matrix-name">
                            {{ t('kinetix.role_name') }}
                        </KinetixLabel>
                        <input
                            id="kx-role-matrix-name"
                            v-model="draftName"
                            type="text"
                            required
                            maxlength="60"
                            :class="inputClass"
                        />
                    </div>

                    <!-- Matrix: scrolls on its own; the header row and the
                         module column stay pinned so long catalogs keep their
                         context while granting. -->
                    <div
                        class="rounded-lg min-h-0 flex-1 overflow-auto border border-border"
                    >
                        <table class="text-sm w-full">
                            <thead>
                                <tr>
                                    <th
                                        class="px-3 py-2 font-medium top-0 left-0 sticky z-30 border-b border-border bg-card text-left"
                                    >
                                        {{ t('kinetix.role_matrix_module') }}
                                    </th>
                                    <th
                                        v-for="column in abilityColumns"
                                        :key="column.key"
                                        class="px-2 py-2 text-xs font-medium top-0 sticky z-20 border-b border-border bg-card text-center whitespace-nowrap"
                                    >
                                        {{ column.label }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="feature in features"
                                    :key="feature.name"
                                    class="group border-b border-border last:border-0"
                                >
                                    <td
                                        class="p-0 left-0 sticky z-10 bg-card group-hover:bg-muted"
                                    >
                                        <button
                                            type="button"
                                            class="px-3 py-2 font-medium text-sm w-full cursor-pointer text-left text-foreground select-none"
                                            :title="
                                                t('kinetix.role_matrix_hint')
                                            "
                                            @click="toggleRow(feature)"
                                        >
                                            {{ feature.label }}
                                        </button>
                                    </td>
                                    <td
                                        v-for="column in abilityColumns"
                                        :key="column.key"
                                        class="px-2 py-2 text-center group-hover:bg-muted/50"
                                    >
                                        <button
                                            v-if="
                                                permissionFor(
                                                    feature,
                                                    column.key,
                                                )
                                            "
                                            type="button"
                                            class="size-6 inline-flex items-center justify-center rounded-md border transition-colors"
                                            :class="
                                                has(
                                                    permissionFor(
                                                        feature,
                                                        column.key,
                                                    ),
                                                )
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-border text-transparent hover:border-primary/50'
                                            "
                                            :aria-pressed="
                                                has(
                                                    permissionFor(
                                                        feature,
                                                        column.key,
                                                    ),
                                                )
                                            "
                                            :aria-label="`${feature.label}: ${column.label}`"
                                            @click="
                                                toggle(
                                                    permissionFor(
                                                        feature,
                                                        column.key,
                                                    ),
                                                )
                                            "
                                        >
                                            <Check class="size-3.5" />
                                        </button>
                                        <span
                                            v-else
                                            class="text-muted-foreground/40"
                                            >—</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="gap-2 flex justify-end">
                        <button
                            type="button"
                            :class="buttonVariants({ variant: 'outline' })"
                            :disabled="saving"
                            @click="emit('update:open', false)"
                        >
                            {{ t('kinetix.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :class="buttonVariants()"
                            :disabled="saving"
                        >
                            {{ t('kinetix.save') }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
