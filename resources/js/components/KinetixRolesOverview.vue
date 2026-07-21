<script setup lang="ts">
import { Check, Pencil, Plus, ShieldCheck, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixRoleEditor } from '@/composables/useKinetixRoleEditor';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { KinetixPermissionFeature, KinetixRole } from '@/types';
import { cn } from './primitives/cn';
import KinetixRoleDeleteDialog from './Roles/KinetixRoleDeleteDialog.vue';
import KinetixRoleEditorModal from './Roles/KinetixRoleEditorModal.vue';

/**
 * At-a-glance roles & permissions overview: role cards (member counts + the
 * modules each role can touch) and a READ-ONLY permission matrix — one row per
 * module, one column per role — so admins can audit who has access to what
 * WITHOUT opening each role. Cells show a check (every ability granted), a
 * `granted/total` badge (partial) or an em-dash (none). Sticky header row and
 * module column keep context while scrolling large catalogs.
 *
 * Editing reuses the same role editor modal as `KinetixRoleMatrix`: the
 * Create button, a card's pencil, or a role's column header open it. Same
 * endpoints, gating and team rules as the other role UIs — gate it behind
 * `roles.manage` where you mount it:
 *
 *     <KinetixCan permission="roles.manage">
 *         <KinetixRolesOverview />
 *     </KinetixCan>
 */

const { t } = useI18n();
const { features, roles, loading, saving, deleting, saveRole, removeRole } =
    useKinetixRoleEditor();

/** How many granted-module lines a role card lists before collapsing to +N. */
const CARD_FEATURE_LINES = 4;

// role key → Set of its permissions, so every matrix cell / card line is O(1).
const permissionSets = computed<Map<string | number, Set<string>>>(() => {
    const map = new Map<string | number, Set<string>>();

    for (const role of roles.value) {
        map.set(roleKey(role), new Set(role.permissions));
    }

    return map;
});

function roleKey(role: KinetixRole): string | number {
    return role.id ?? role.name;
}

interface MatrixCell {
    granted: number;
    total: number;
    state: 'full' | 'partial' | 'none';
}

function cellFor(
    feature: KinetixPermissionFeature,
    role: KinetixRole,
): MatrixCell {
    const permissions = permissionSets.value.get(roleKey(role));
    const total = feature.abilities.length;
    let granted = 0;

    for (const ability of feature.abilities) {
        if (permissions?.has(ability.permission)) {
            granted++;
        }
    }

    return {
        granted,
        total,
        state: granted === 0 ? 'none' : granted === total ? 'full' : 'partial',
    };
}

/** The whole matrix precomputed per render: one row per module, one cell per role. */
const matrixRows = computed(() =>
    features.value.map((feature) => ({
        feature,
        cells: roles.value.map((role) => cellFor(feature, role)),
    })),
);

/** Labels of the modules a role has at least one ability on. */
function grantedFeatureLabels(role: KinetixRole): string[] {
    const permissions = permissionSets.value.get(roleKey(role));

    return features.value
        .filter((feature) =>
            feature.abilities.some((ability) =>
                permissions?.has(ability.permission),
            ),
        )
        .map((feature) => feature.label);
}

// --- Editor modal (shared with KinetixRoleMatrix) ---------------------------

const formOpen = ref(false);
const editing = ref<KinetixRole | null>(null);

function openCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(role: KinetixRole): void {
    editing.value = role;
    formOpen.value = true;
}

async function onSave(role: KinetixRole): Promise<void> {
    if (await saveRole(role)) {
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

        <!-- Loading skeleton -->
        <div v-if="loading" class="gap-3 sm:grid-cols-2 xl:grid-cols-3 grid">
            <div
                v-for="i in 3"
                :key="i"
                class="h-32 animate-pulse rounded-xl bg-muted"
            ></div>
        </div>

        <p v-else-if="roles.length === 0" class="text-sm text-muted-foreground">
            {{ t('kinetix.no_roles') }}
        </p>

        <template v-else>
            <!-- Role cards: what each role can touch, at a glance -->
            <div class="gap-3 sm:grid-cols-2 xl:grid-cols-3 grid">
                <div
                    v-for="role in roles"
                    :key="String(roleKey(role))"
                    class="gap-2 rounded-xl p-4 flex flex-col border border-border bg-card"
                >
                    <div class="gap-2 flex items-center justify-between">
                        <div class="gap-2 min-w-0 flex items-center">
                            <span
                                class="size-8 rounded-lg inline-flex shrink-0 items-center justify-center bg-primary/10"
                            >
                                <ShieldCheck class="size-4 text-primary" />
                            </span>
                            <p class="font-semibold truncate text-foreground">
                                {{ role.name }}
                            </p>
                        </div>
                        <span
                            v-if="
                                role.usersCount !== null &&
                                role.usersCount !== undefined
                            "
                            class="px-2 py-0.5 font-medium rounded-md bg-secondary text-[11px] whitespace-nowrap text-secondary-foreground"
                        >
                            {{
                                t('kinetix.role_members', {
                                    count: role.usersCount,
                                })
                            }}
                        </span>
                    </div>

                    <ul class="space-y-1">
                        <li
                            v-for="label in grantedFeatureLabels(role).slice(
                                0,
                                CARD_FEATURE_LINES,
                            )"
                            :key="label"
                            class="gap-1.5 text-xs flex items-center text-muted-foreground"
                        >
                            <span
                                class="size-1 shrink-0 rounded-full bg-muted-foreground"
                            ></span>
                            {{ label }}
                        </li>
                        <li
                            v-if="
                                grantedFeatureLabels(role).length >
                                CARD_FEATURE_LINES
                            "
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t('kinetix.role_more_features', {
                                    count:
                                        grantedFeatureLabels(role).length -
                                        CARD_FEATURE_LINES,
                                })
                            }}
                        </li>
                    </ul>

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

            <!-- Read-only permission matrix: modules × roles -->
            <div class="rounded-xl border border-border bg-card">
                <h4
                    class="px-4 py-3 text-sm font-semibold border-b border-border text-foreground"
                >
                    {{ t('kinetix.permission_matrix') }}
                </h4>
                <div class="max-h-[60vh] overflow-auto">
                    <table class="text-sm w-full">
                        <thead>
                            <tr>
                                <th
                                    class="px-4 py-2.5 text-xs font-medium top-0 left-0 sticky z-30 border-b border-border bg-card text-left text-muted-foreground"
                                >
                                    {{ t('kinetix.role_matrix_module') }}
                                </th>
                                <th
                                    v-for="role in roles"
                                    :key="String(roleKey(role))"
                                    class="p-0 top-0 sticky z-20 border-b border-border bg-card"
                                >
                                    <button
                                        type="button"
                                        class="px-3 py-2.5 text-xs font-medium w-full text-center whitespace-nowrap text-muted-foreground transition-colors hover:text-foreground"
                                        :title="t('kinetix.edit')"
                                        @click="openEdit(role)"
                                    >
                                        {{ role.name }}
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in matrixRows"
                                :key="row.feature.name"
                                class="group border-b border-border last:border-0"
                            >
                                <td
                                    class="px-4 py-2.5 font-medium left-0 sticky z-10 bg-card text-foreground group-hover:bg-muted"
                                >
                                    {{ row.feature.label }}
                                </td>
                                <td
                                    v-for="(cell, index) in row.cells"
                                    :key="String(roleKey(roles[index]))"
                                    class="px-3 py-2.5 text-center group-hover:bg-muted/50"
                                >
                                    <span
                                        v-if="cell.state === 'full'"
                                        class="size-5 rounded inline-flex items-center justify-center bg-primary/10 text-primary"
                                        :title="t('kinetix.role_matrix_full')"
                                    >
                                        <Check class="size-3.5" />
                                    </span>
                                    <span
                                        v-else-if="cell.state === 'partial'"
                                        class="px-1.5 py-0.5 font-medium rounded bg-secondary text-[11px] text-secondary-foreground"
                                        :title="
                                            t('kinetix.role_matrix_partial', {
                                                granted: cell.granted,
                                                total: cell.total,
                                            })
                                        "
                                    >
                                        {{ cell.granted }}/{{ cell.total }}
                                    </span>
                                    <span
                                        v-else
                                        class="text-muted-foreground/40"
                                        :title="t('kinetix.role_matrix_none')"
                                        >—</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- Shared editor modal (sticky header + module column) -->
        <KinetixRoleEditorModal
            v-model:open="formOpen"
            :role="editing"
            :features="features"
            :saving="saving"
            @save="onSave"
        />

        <!-- Delete confirmation -->
        <KinetixRoleDeleteDialog
            :open="deleteTarget !== null"
            :deleting="deleting"
            @update:open="(v: boolean) => !v && (deleteTarget = null)"
            @confirm="confirmDelete"
        />
    </div>
</template>
