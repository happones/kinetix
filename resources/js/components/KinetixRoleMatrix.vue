<script setup lang="ts">
import { Check, Pencil, Plus, ShieldCheck, Trash2, X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixRoleEditor } from '@/composables/useKinetixRoleEditor';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import type { KinetixPermissionFeature, KinetixRole } from '@/types';
import KinetixLabel from './KinetixLabel.vue';
import { cn } from './primitives/cn';

/**
 * A role manager built around a module × ability MATRIX: role cards (with
 * member counts) and a modal whose table has one row per feature and one
 * column per ability — click a module name to toggle its whole row. An
 * alternative to `KinetixRoleManager` (grouped checkbox lists) for apps with
 * CRUD-shaped permission catalogs. Same endpoints, gating and team rules.
 *
 * Gate it behind `roles.manage` where you mount it:
 *
 *     <KinetixCan permission="roles.manage">
 *         <KinetixRoleMatrix />
 *     </KinetixCan>
 */

const { t } = useI18n();
const { features, roles, loading, saving, deleting, saveRole, removeRole } =
    useKinetixRoleEditor();

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

/** Distinct ability columns across the catalog, canonically ordered. */
const abilityColumns = computed<{ key: string; label: string }[]>(() => {
    const seen = new Map<string, string>();

    for (const feature of features.value) {
        for (const ability of feature.abilities) {
            if (!seen.has(ability.key)) {
                seen.set(ability.key, ability.label);
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

    for (const feature of features.value) {
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

// --- Editor modal ---------------------------------------------------------

const formOpen = ref(false);
const editing = ref<KinetixRole | null>(null);
const draftName = ref('');
const draftPermissions = ref<string[]>([]);

function openCreate(): void {
    editing.value = null;
    draftName.value = '';
    draftPermissions.value = [];
    formOpen.value = true;
}

function openEdit(role: KinetixRole): void {
    editing.value = role;
    draftName.value = role.name;
    draftPermissions.value = [...role.permissions];
    formOpen.value = true;
}

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

async function submit(): Promise<void> {
    if (!draftName.value.trim()) {
        return;
    }

    const ok = await saveRole({
        id: editing.value?.id ?? null,
        name: draftName.value.trim(),
        permissions: draftPermissions.value,
    });

    if (ok) {
        formOpen.value = false;
    }
}

// --- Delete confirmation ---------------------------------------------------

const deleteTarget = ref<KinetixRole | null>(null);

async function confirmDelete(): Promise<void> {
    if (!deleteTarget.value) {
        return;
    }

    if (await removeRole(deleteTarget.value)) {
        deleteTarget.value = null;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="gap-3 flex items-center justify-between">
            <h3 class="text-base font-semibold text-foreground">
                {{ t('kinetix.roles_title') }}
            </h3>
            <button
                type="button"
                :class="cn(buttonVariants({ size: 'sm' }), 'gap-1.5')"
                @click="openCreate"
            >
                <Plus class="size-4" />
                {{ t('kinetix.create_role') }}
            </button>
        </div>

        <p
            v-if="!loading && roles.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ t('kinetix.no_roles') }}
        </p>

        <!-- Role cards -->
        <div class="gap-3 sm:grid-cols-2 xl:grid-cols-3 grid">
            <div
                v-for="role in roles"
                :key="String(role.id)"
                class="gap-2 rounded-xl p-4 flex flex-col border border-border bg-card"
            >
                <div class="gap-2 flex items-center justify-between">
                    <div class="gap-2 min-w-0 flex items-center">
                        <ShieldCheck class="size-4 shrink-0 text-primary" />
                        <p class="font-semibold truncate text-foreground">
                            {{ role.name }}
                        </p>
                    </div>
                    <span
                        v-if="
                            role.usersCount !== null &&
                            role.usersCount !== undefined
                        "
                        class="px-2 py-0.5 font-medium rounded-md bg-secondary text-[11px] text-secondary-foreground"
                    >
                        {{
                            t('kinetix.role_members', {
                                count: role.usersCount,
                            })
                        }}
                    </span>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('kinetix.role_permissions_count', {
                            count: role.permissions.length,
                        })
                    }}
                </p>
                <div
                    class="gap-1 pt-2 mt-auto flex justify-end border-t border-border"
                >
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        :title="t('kinetix.edit')"
                        @click="openEdit(role)"
                    >
                        <Pencil class="size-4" />
                    </button>
                    <button
                        type="button"
                        :class="
                            cn(
                                buttonVariants({
                                    variant: 'ghost',
                                    size: 'icon-sm',
                                }),
                                'text-muted-foreground hover:text-destructive',
                            )
                        "
                        :title="t('kinetix.delete')"
                        @click="deleteTarget = role"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Editor modal with the ability matrix -->
        <DialogRoot v-model:open="formOpen">
            <DialogPortal>
                <DialogOverlay
                    class="inset-0 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed z-50"
                />
                <DialogContent
                    class="max-w-2xl rounded-xl p-6 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-1/2 left-1/2 z-50 max-h-[90vh] w-[92vw] -translate-x-1/2 -translate-y-1/2 overflow-auto border border-border bg-card text-card-foreground outline-none"
                >
                    <form class="gap-4 flex flex-col" @submit.prevent="submit">
                        <div class="flex items-start justify-between">
                            <div>
                                <DialogTitle
                                    class="text-lg font-semibold tracking-tight leading-none"
                                >
                                    {{
                                        editing
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

                        <!-- Matrix -->
                        <div
                            class="rounded-lg overflow-x-auto border border-border"
                        >
                            <table class="text-sm w-full">
                                <thead>
                                    <tr
                                        class="border-b border-border bg-muted/50"
                                    >
                                        <th
                                            class="px-3 py-2 font-medium text-left"
                                        >
                                            {{
                                                t('kinetix.role_matrix_module')
                                            }}
                                        </th>
                                        <th
                                            v-for="column in abilityColumns"
                                            :key="column.key"
                                            class="px-2 py-2 text-xs font-medium text-center whitespace-nowrap"
                                        >
                                            {{ column.label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="feature in features"
                                        :key="feature.name"
                                        class="border-b border-border last:border-0 hover:bg-accent/50"
                                    >
                                        <td
                                            class="px-3 py-2 font-medium cursor-pointer text-foreground select-none"
                                            @click="toggleRow(feature)"
                                        >
                                            {{ feature.label }}
                                        </td>
                                        <td
                                            v-for="column in abilityColumns"
                                            :key="column.key"
                                            class="px-2 py-2 text-center"
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
                                @click="formOpen = false"
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

        <!-- Delete confirmation -->
        <DialogRoot
            :open="deleteTarget !== null"
            @update:open="(v: boolean) => !v && (deleteTarget = null)"
        >
            <DialogPortal>
                <DialogOverlay class="inset-0 bg-black/80 fixed z-50" />
                <DialogContent
                    class="max-w-sm rounded-xl p-6 shadow-lg fixed top-1/2 left-1/2 z-50 w-[92vw] -translate-x-1/2 -translate-y-1/2 border border-border bg-card text-card-foreground outline-none"
                >
                    <DialogTitle
                        class="text-lg font-semibold tracking-tight leading-none"
                    >
                        {{ t('kinetix.delete') }}
                    </DialogTitle>
                    <DialogDescription
                        class="mt-1.5 text-sm text-muted-foreground"
                    >
                        {{ t('kinetix.confirm_delete') }}
                    </DialogDescription>
                    <div class="mt-4 gap-2 flex justify-end">
                        <button
                            type="button"
                            :class="buttonVariants({ variant: 'outline' })"
                            @click="deleteTarget = null"
                        >
                            {{ t('kinetix.cancel') }}
                        </button>
                        <button
                            type="button"
                            :class="buttonVariants({ variant: 'destructive' })"
                            :disabled="deleting"
                            @click="confirmDelete"
                        >
                            {{ t('kinetix.delete') }}
                        </button>
                    </div>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>
    </div>
</template>
