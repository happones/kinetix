<script setup lang="ts">
import { Globe, Pencil, Plus, ShieldCheck, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixCan } from '@/composables/useKinetixCan';
import { useKinetixRoleEditor } from '@/composables/useKinetixRoleEditor';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixRole } from '@/types/kinetix';
import { cn } from './primitives/cn';
import KinetixRoleDeleteDialog from './Roles/KinetixRoleDeleteDialog.vue';
import KinetixRoleEditorModal from './Roles/KinetixRoleEditorModal.vue';

/**
 * A role manager built around a module × ability MATRIX: role cards (with
 * member counts) and a modal whose table has one row per feature and one
 * column per ability — click a module name to toggle its whole row; the
 * header row and module column stay sticky while the catalog scrolls. An
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
const { isSuperAdmin } = useKinetixCan();

// Global (team-NULL) roles apply to every team — the server only lets a
// super-admin modify them, so mirror that in the UI.
const canModify = (role: KinetixRole): boolean =>
    !role.isGlobal || isSuperAdmin.value;

// --- Editor modal ---------------------------------------------------------

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
                        <span
                            v-if="role.isGlobal"
                            class="gap-1 px-1.5 py-0.5 font-medium rounded inline-flex shrink-0 items-center bg-secondary text-[11px] text-secondary-foreground"
                            :title="t('kinetix.role_global_hint')"
                        >
                            <Globe class="size-3" />
                            {{ t('kinetix.role_global') }}
                        </span>
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
                    v-if="canModify(role)"
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

        <!-- Editor modal with the ability matrix (sticky header + module column) -->
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
