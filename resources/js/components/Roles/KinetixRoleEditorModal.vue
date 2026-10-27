<script setup lang="ts">
import { ChevronRight, Search } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import type { KinetixPermissionFeature, KinetixRole } from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixCheckbox from '../KinetixCheckbox.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixModal from '../primitives/KinetixModal.vue';

/**
 * The role editor dialog shared by `KinetixRoleMatrix` and
 * `KinetixRolesOverview`: a name field plus a module × ability matrix. Only
 * the matrix scrolls — inside its own container, with a sticky header row AND
 * a sticky module column, so both the ability being granted and the module it
 * belongs to stay visible. The name field, the filter/select-all bar and the
 * Cancel/Save footer stay pinned.
 *
 * Bulk selection is tri-state at every level: "Select all" (every module the
 * filter shows), a column header (one ability on every shown module), a group
 * title and a module row. Each shows a dash when partially granted; a click
 * grants everything it covers, a second click clears it.
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

// --- Filter -----------------------------------------------------------------

const filter = ref('');

/** Case- and accent-insensitive: "acces" finds "Accès". */
const normalize = (value: string): string =>
    value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * The modules the filter shows: a match on the module's label, name or group,
 * or on one of its custom abilities (they're the ones hidden in a chip).
 */
const visibleFeatures = computed<KinetixPermissionFeature[]>(() => {
    const query = normalize(filter.value.trim());

    if (!query) {
        return props.features;
    }

    return props.features.filter((feature) =>
        [
            feature.label,
            feature.name,
            feature.group ?? '',
            ...customAbilities(feature).map((ability) => ability.label),
        ].some((text) => normalize(text).includes(query)),
    );
});

// --- Grouped rows (Feature::group('HR') → titled sections) -------------------

const featurePermissions = (feature: KinetixPermissionFeature): string[] =>
    feature.abilities.map((ability) => ability.permission);

const groupedFeatures = computed(() => {
    const map = new Map<string | null, KinetixPermissionFeature[]>();

    for (const feature of visibleFeatures.value) {
        const key = feature.group ?? null;
        map.set(key, [...(map.get(key) ?? []), feature]);
    }

    // Named groups first (declaration order), ungrouped last.
    return [...map.entries()]
        .sort(([a], [b]) => Number(a === null) - Number(b === null))
        .map(([group, features]) => ({
            group,
            features,
            permissions: features.flatMap(featurePermissions),
        }));
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

/** Every permission the filter shows — the scope of "Select all" / "Clear all". */
const visiblePermissions = computed<string[]>(() =>
    visibleFeatures.value.flatMap(featurePermissions),
);

/** ability key → that ability's permission on every shown module. */
const columnPermissions = computed<Map<string, string[]>>(
    () =>
        new Map(
            abilityColumns.value.map((column) => [
                column.key,
                visibleFeatures.value
                    .map((feature) => permissionFor(feature, column.key))
                    .filter(
                        (permission): permission is string =>
                            permission !== null,
                    ),
            ]),
        ),
);

/** The catalog's permissions, so stale names in a role don't skew the count. */
const catalogPermissions = computed<Set<string>>(
    () => new Set(props.features.flatMap(featurePermissions)),
);

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
            filter.value = '';
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

const grantedCount = computed<number>(
    () =>
        [...catalogPermissions.value].filter((name) => draftSet.value.has(name))
            .length,
);

/** Grant (or revoke) a set of permissions at once. */
function setPermissions(permissions: string[], granted: boolean): void {
    if (granted) {
        draftPermissions.value = [
            ...new Set([...draftPermissions.value, ...permissions]),
        ];

        return;
    }

    const revoked = new Set(permissions);

    draftPermissions.value = draftPermissions.value.filter(
        (name) => !revoked.has(name),
    );
}

/**
 * A bulk checkbox's props for the permissions it covers: checked when all are
 * granted, a dash when some are, disabled when it covers none.
 */
function bulkState(permissions: string[]): {
    modelValue: boolean;
    indeterminate: boolean;
    disabled: boolean;
} {
    const granted = permissions.filter((name) => draftSet.value.has(name));

    return {
        modelValue:
            permissions.length > 0 && granted.length === permissions.length,
        indeterminate:
            granted.length > 0 && granted.length < permissions.length,
        disabled: permissions.length === 0,
    };
}

const formId = `kx-role-editor-${useId()}`;

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
        max-width="sm:max-w-4xl"
        :processing="saving"
        content-scroll
        placement="top"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <form
            :id="formId"
            class="gap-4 min-h-0 flex flex-1 flex-col"
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
                 visible in every team). A role's team can't change later, so
                 the toggle exists only on create. -->
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

            <!-- Bulk bar: pinned above the matrix. Select all / Clear all act
                 on what the filter shows; the count covers the whole role. -->
            <div
                class="gap-x-4 gap-y-2 flex flex-wrap items-center"
                data-test="role-editor-bulk"
            >
                <div class="min-w-0 basis-48 relative flex-1">
                    <Search
                        class="left-2.5 size-4 pointer-events-none absolute top-1/2 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <!-- Enter would submit the role while the user is only
                         narrowing the list. -->
                    <input
                        v-model="filter"
                        type="search"
                        :placeholder="t('kinetix.role_filter_modules')"
                        :aria-label="t('kinetix.role_filter_modules')"
                        :class="[inputClass, 'pl-8']"
                        @keydown.enter.prevent
                    />
                </div>
                <div class="gap-3 flex items-center">
                    <label
                        class="gap-2 text-sm font-medium flex cursor-pointer items-center whitespace-nowrap text-foreground"
                    >
                        <KinetixCheckbox
                            v-bind="bulkState(visiblePermissions)"
                            data-test="role-editor-select-all"
                            @update:model-value="
                                (granted: boolean) =>
                                    setPermissions(visiblePermissions, granted)
                            "
                        />
                        {{ t('kinetix.select_all') }}
                    </label>
                    <button
                        type="button"
                        :class="
                            buttonVariants({ variant: 'ghost', size: 'sm' })
                        "
                        :disabled="
                            !visiblePermissions.some((name) => has(name))
                        "
                        data-test="role-editor-clear-all"
                        @click="setPermissions(visiblePermissions, false)"
                    >
                        {{ t('kinetix.clear_all') }}
                    </button>
                    <span
                        class="text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                        data-test="role-editor-count"
                    >
                        {{
                            t('kinetix.role_selected_count', {
                                count: grantedCount,
                                total: catalogPermissions.size,
                            })
                        }}
                    </span>
                </div>
            </div>

            <!-- Matrix: the only part that scrolls; the header row and the
                 module column stay pinned so long catalogs keep their context
                 while granting. -->
            <div
                class="rounded-lg min-h-0 flex-1 overflow-auto overscroll-contain border border-border"
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
                                class="px-2 py-2 text-xs font-medium top-0 sticky z-20 border-b border-border bg-card text-center align-bottom"
                            >
                                <!-- Two-word labels ("Delete permanently")
                                     wrap instead of widening every column. -->
                                <span
                                    class="gap-1.5 leading-tight min-w-14 flex flex-col items-center"
                                >
                                    {{ column.label }}
                                    <!-- Grant this ability on every shown module. -->
                                    <KinetixCheckbox
                                        v-bind="
                                            bulkState(
                                                columnPermissions.get(
                                                    column.key,
                                                ) ?? [],
                                            )
                                        "
                                        :aria-label="
                                            t('kinetix.role_grant_column', {
                                                ability: column.label,
                                            })
                                        "
                                        @update:model-value="
                                            (granted: boolean) =>
                                                setPermissions(
                                                    columnPermissions.get(
                                                        column.key,
                                                    ) ?? [],
                                                    granted,
                                                )
                                        "
                                    />
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-if="filter.trim() && visibleFeatures.length === 0"
                        >
                            <td
                                :colspan="abilityColumns.length + 1"
                                class="px-3 py-8 text-sm text-center text-muted-foreground"
                            >
                                {{
                                    t('kinetix.role_no_modules_match', {
                                        query: filter.trim(),
                                    })
                                }}
                            </td>
                        </tr>

                        <template
                            v-for="section in groupedFeatures"
                            :key="section.group ?? '__ungrouped'"
                        >
                            <!-- Section divider (Feature::group('HR')), with a
                                 checkbox for the whole group. -->
                            <tr v-if="hasGroups && section.group">
                                <td
                                    :colspan="abilityColumns.length + 1"
                                    class="px-3 py-1.5 text-xs font-semibold tracking-wide border-b border-border bg-muted/50 text-muted-foreground uppercase"
                                >
                                    <label
                                        class="gap-2 left-3 sticky inline-flex cursor-pointer items-center"
                                    >
                                        <KinetixCheckbox
                                            v-bind="
                                                bulkState(section.permissions)
                                            "
                                            @update:model-value="
                                                (granted: boolean) =>
                                                    setPermissions(
                                                        section.permissions,
                                                        granted,
                                                    )
                                            "
                                        />
                                        {{ section.group }}
                                    </label>
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
                                            <!-- The whole row: every ability,
                                                 custom ones included. -->
                                            <label
                                                class="gap-2.5 px-3 py-2 font-medium text-sm min-w-0 flex flex-1 cursor-pointer items-center text-foreground select-none"
                                            >
                                                <KinetixCheckbox
                                                    v-bind="
                                                        bulkState(
                                                            featurePermissions(
                                                                feature,
                                                            ),
                                                        )
                                                    "
                                                    @update:model-value="
                                                        (granted: boolean) =>
                                                            setPermissions(
                                                                featurePermissions(
                                                                    feature,
                                                                ),
                                                                granted,
                                                            )
                                                    "
                                                />
                                                <span class="whitespace-nowrap">
                                                    {{ feature.label }}
                                                </span>
                                            </label>
                                            <!-- Custom abilities live INSIDE
                                                 the row (full labels), never as
                                                 shared columns. -->
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
                                        <span
                                            v-if="
                                                permissionFor(
                                                    feature,
                                                    column.key,
                                                )
                                            "
                                            class="flex justify-center"
                                        >
                                            <KinetixCheckbox
                                                :model-value="
                                                    has(
                                                        permissionFor(
                                                            feature,
                                                            column.key,
                                                        ),
                                                    )
                                                "
                                                :aria-label="`${feature.label}: ${column.label}`"
                                                @update:model-value="
                                                    (granted: boolean) =>
                                                        setPermissions(
                                                            [
                                                                permissionFor(
                                                                    feature,
                                                                    column.key,
                                                                )!,
                                                            ],
                                                            granted,
                                                        )
                                                "
                                            />
                                        </span>
                                        <span
                                            v-else
                                            class="text-muted-foreground/40"
                                            aria-hidden="true"
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
                                                        (granted: boolean) =>
                                                            setPermissions(
                                                                [
                                                                    ability.permission,
                                                                ],
                                                                granted,
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
        </form>

        <!-- Pinned footer, outside the scroller; submits the form by id. -->
        <template #footer>
            <button
                type="button"
                :class="buttonVariants({ variant: 'outline' })"
                :disabled="saving"
                @click="emit('update:open', false)"
            >
                {{ t('kinetix.cancel') }}
            </button>
            <KinetixButton type="submit" :form="formId" :loading="saving">
                {{ role ? t('kinetix.save') : t('kinetix.create') }}
            </KinetixButton>
        </template>
    </KinetixModal>
</template>
