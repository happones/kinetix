<script setup lang="ts">
import { Check, ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import type { KinetixPermissionFeature, KinetixRole } from '@/types/kinetix';
import KinetixCheckbox from '../KinetixCheckbox.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixModal from '../primitives/KinetixModal.vue';

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
    /**
     * Offer a "Global role (all teams)" toggle on CREATE. Pass the viewer's
     * super-admin flag — the server rejects the flag from anyone else.
     */
    canCreateGlobal?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'save', role: KinetixRole): void;
}>();

const { t } = useI18n();

/**
 * Canonical ability columns, in order. `access` (access-only modules) leads,
 * then the CRUD lifecycle. ONLY these ever become columns: the header row has
 * a fixed vocabulary and never grows, no matter how many modules or custom
 * abilities the catalog declares. Everything non-canonical renders inside its
 * own row (expandable, full labels) instead.
 */
const CANONICAL_ORDER = [
    'access',
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
 * render the generic translation.
 */
const CANONICAL_LABELS: Record<string, string> = {
    access: 'kinetix.access',
    viewAny: 'kinetix.view_any',
    view: 'kinetix.view',
    create: 'kinetix.create',
    update: 'kinetix.edit',
    delete: 'kinetix.delete',
    deleteAny: 'kinetix.delete_any',
    restore: 'kinetix.restore',
    forceDelete: 'kinetix.force_delete',
};

const CANONICAL_SET = new Set(CANONICAL_ORDER);

/** The canonical columns present anywhere in the catalog, in order. */
const abilityColumns = computed<{ key: string; label: string }[]>(() => {
    const present = new Set<string>();

    for (const feature of props.features) {
        for (const ability of feature.abilities) {
            if (CANONICAL_SET.has(ability.key)) {
                present.add(ability.key);
            }
        }
    }

    return CANONICAL_ORDER.filter((key) => present.has(key)).map((key) => ({
        key,
        label: t(CANONICAL_LABELS[key]),
    }));
});

/** A feature's non-canonical abilities (rendered inside its row, full labels). */
const customAbilities = (
    feature: KinetixPermissionFeature,
): KinetixPermissionFeature['abilities'] =>
    feature.abilities.filter((ability) => !CANONICAL_SET.has(ability.key));

/** How many of a feature's custom abilities the draft currently grants. */
const customGrantedCount = (feature: KinetixPermissionFeature): number =>
    customAbilities(feature).filter((ability) => has(ability.permission))
        .length;

// --- Expandable custom-ability rows -----------------------------------------

const expanded = ref<Set<string>>(new Set());

function toggleExpanded(featureName: string): void {
    const next = new Set(expanded.value);

    if (next.has(featureName)) {
        next.delete(featureName);
    } else {
        next.add(featureName);
    }

    expanded.value = next;
}

// --- Grouped rows (Feature::group('HR') → titled sections) -------------------

const groupedFeatures = computed(() => {
    const map = new Map<string | null, KinetixPermissionFeature[]>();

    for (const feature of props.features) {
        const key = feature.group ?? null;
        map.set(key, [...(map.get(key) ?? []), feature]);
    }

    // Named groups first (declaration order), ungrouped last.
    return [...map.entries()]
        .sort(([a], [b]) => Number(a === null) - Number(b === null))
        .map(([group, features]) => ({ group, features }));
});

const hasGroups = computed(() =>
    props.features.some((feature) => feature.group),
);

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
const draftGlobal = ref(false);

watch(
    () => props.open,
    (open) => {
        if (open) {
            draftName.value = props.role?.name ?? '';
            draftPermissions.value = [...(props.role?.permissions ?? [])];
            draftGlobal.value = false;
        }
    },
);

// Only a NEW role can be global — a role's team can't be changed afterwards.
const offersGlobalToggle = computed<boolean>(
    () => !props.role?.id && props.canCreateGlobal === true,
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
        ...(offersGlobalToggle.value && draftGlobal.value
            ? { global: true }
            : {}),
    });
}
</script>

<template>
    <KinetixModal
        :open="open"
        :title="role ? t('kinetix.edit') : t('kinetix.create_role')"
        :description="t('kinetix.role_matrix_hint')"
        max-width="sm:max-w-2xl"
        :processing="saving"
        scroll-body
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <form
            class="gap-4 min-h-0 flex h-full flex-col"
            @submit.prevent="submit"
        >
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

            <!-- Super-admin only: create the role as GLOBAL (team-NULL,
                         visible in every team). A role's team can't change
                         later, so the toggle exists only on create. -->
            <label
                v-if="offersGlobalToggle"
                class="gap-2 text-sm flex cursor-pointer items-start text-foreground"
            >
                <KinetixCheckbox
                    :model-value="draftGlobal"
                    @update:model-value="draftGlobal = $event"
                />
                <span class="min-w-0">
                    {{ t('kinetix.role_global_create_label') }}
                    <span class="text-xs block text-muted-foreground">
                        {{ t('kinetix.role_global_create_hint') }}
                    </span>
                </span>
            </label>

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
                        <template
                            v-for="section in groupedFeatures"
                            :key="section.group ?? '__ungrouped'"
                        >
                            <!-- Section divider (Feature::group('HR')) -->
                            <tr v-if="hasGroups && section.group">
                                <td
                                    :colspan="abilityColumns.length + 1"
                                    class="px-3 py-1.5 text-xs font-semibold tracking-wide border-b border-border bg-muted/50 text-muted-foreground uppercase"
                                >
                                    {{ section.group }}
                                </td>
                            </tr>

                            <template
                                v-for="feature in section.features"
                                :key="feature.name"
                            >
                                <tr
                                    class="group border-b border-border last:border-0"
                                >
                                    <td
                                        class="p-0 left-0 sticky z-10 bg-card group-hover:bg-muted"
                                    >
                                        <span class="flex items-center">
                                            <button
                                                type="button"
                                                class="px-3 py-2 font-medium text-sm min-w-0 flex-1 cursor-pointer text-left text-foreground select-none"
                                                :title="
                                                    t(
                                                        'kinetix.role_matrix_hint',
                                                    )
                                                "
                                                @click="toggleRow(feature)"
                                            >
                                                {{ feature.label }}
                                            </button>
                                            <!-- Custom abilities live INSIDE the row (full
                                                         labels), never as shared columns. -->
                                            <button
                                                v-if="
                                                    customAbilities(feature)
                                                        .length > 0
                                                "
                                                type="button"
                                                class="gap-0.5 mr-2 px-1.5 py-0.5 rounded font-medium inline-flex shrink-0 items-center bg-secondary text-[11px] text-secondary-foreground transition-colors hover:bg-secondary/80"
                                                :aria-expanded="
                                                    expanded.has(feature.name)
                                                "
                                                :aria-label="`${feature.label}: ${t('kinetix.role_custom_abilities')}`"
                                                @click="
                                                    toggleExpanded(feature.name)
                                                "
                                            >
                                                <ChevronRight
                                                    class="size-3 transition-transform"
                                                    :class="
                                                        expanded.has(
                                                            feature.name,
                                                        ) && 'rotate-90'
                                                    "
                                                />
                                                {{
                                                    customGrantedCount(feature)
                                                }}/{{
                                                    customAbilities(feature)
                                                        .length
                                                }}
                                            </button>
                                        </span>
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

                                <!-- Expanded: the module's custom abilities with full labels -->
                                <tr
                                    v-if="expanded.has(feature.name)"
                                    class="border-b border-border last:border-0"
                                >
                                    <td
                                        :colspan="abilityColumns.length + 1"
                                        class="px-4 py-2.5 bg-muted/30"
                                    >
                                        <div
                                            class="gap-x-6 gap-y-2 flex flex-wrap"
                                        >
                                            <label
                                                v-for="ability in customAbilities(
                                                    feature,
                                                )"
                                                :key="ability.permission"
                                                class="gap-2 text-sm flex cursor-pointer items-center text-foreground"
                                            >
                                                <KinetixCheckbox
                                                    :model-value="
                                                        has(ability.permission)
                                                    "
                                                    @update:model-value="
                                                        toggle(
                                                            ability.permission,
                                                        )
                                                    "
                                                />
                                                {{ ability.label }}
                                                <span
                                                    class="font-mono text-xs text-muted-foreground"
                                                    >{{
                                                        ability.permission
                                                    }}</span
                                                >
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </template>
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
    </KinetixModal>
</template>
