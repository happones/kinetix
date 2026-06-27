<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixTokens } from '@/composables/useKinetixTokens';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import type { KinetixToken } from '@/types';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixLabel from './KinetixLabel.vue';

/**
 * Drop-in self-service personal access token dashboard: list tokens, create a
 * token with a chosen set of scopes, reveal the plaintext value exactly once,
 * and revoke. Requires the User model to use Laravel\Sanctum\HasApiTokens.
 */
const { t } = useI18n();
const { tokens, scopes, load, create, remove } = useKinetixTokens();

const creating = ref(false);
const newName = ref('');
const selectedScopes = ref<string[]>([]);
const revealed = ref<string | null>(null);

const hasScopes = computed(() => Object.keys(scopes.value).length > 0);

onMounted(load);

function startCreate(): void {
    creating.value = true;
    newName.value = '';
    selectedScopes.value = [];
}

function cancelCreate(): void {
    creating.value = false;
    newName.value = '';
    selectedScopes.value = [];
}

function toggleScope(name: string, checked: boolean): void {
    selectedScopes.value = checked
        ? [...selectedScopes.value, name]
        : selectedScopes.value.filter((s) => s !== name);
}

async function save(): Promise<void> {
    if (!newName.value.trim()) {
        return;
    }

    try {
        const result = await create({
            name: newName.value.trim(),
            abilities: selectedScopes.value,
        });

        if (result?.plainTextToken) {
            revealed.value = result.plainTextToken;
        }

        cancelCreate();
        await load();
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : t('kinetix.save_failed'),
        );
    }
}

async function copyToken(): Promise<void> {
    if (!revealed.value) {
        return;
    }

    await navigator.clipboard?.writeText(revealed.value);
    toast.success(t('kinetix.token_copied'));
}

async function onDelete(token: KinetixToken): Promise<void> {
    try {
        await remove(token);
        await load();
        toast.success(t('kinetix.token_revoked'));
    } catch {
        toast.error(t('kinetix.delete_failed'));
    }
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : t('kinetix.token_never');
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-foreground">
                {{ t('kinetix.tokens_title') }}
            </h2>
            <button
                v-if="!creating"
                type="button"
                :class="buttonVariants({ size: 'sm' })"
                @click="startCreate"
            >
                {{ t('kinetix.token_add') }}
            </button>
        </div>

        <!-- Reveal-once banner -->
        <div
            v-if="revealed"
            class="space-y-2 rounded-lg p-4 border border-border bg-muted/50"
        >
            <p class="text-sm font-medium text-foreground">
                {{ t('kinetix.token_reveal_notice') }}
            </p>
            <div class="gap-2 flex items-center">
                <code
                    class="min-w-0 px-3 py-2 font-mono text-sm flex-1 truncate rounded-md bg-background text-foreground"
                    >{{ revealed }}</code
                >
                <button
                    type="button"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    @click="copyToken"
                >
                    {{ t('kinetix.token_copy') }}
                </button>
                <button
                    type="button"
                    :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
                    @click="revealed = null"
                >
                    {{ t('kinetix.token_dismiss') }}
                </button>
            </div>
        </div>

        <!-- Editor -->
        <div
            v-if="creating"
            class="space-y-3 rounded-lg p-4 border border-border bg-card"
        >
            <div class="space-y-2">
                <KinetixLabel for="token-name">{{
                    t('kinetix.token_name')
                }}</KinetixLabel>
                <input
                    id="token-name"
                    v-model="newName"
                    type="text"
                    :class="inputClass"
                    :placeholder="t('kinetix.token_name_placeholder')"
                />
            </div>

            <div v-if="hasScopes" class="space-y-2">
                <KinetixLabel>{{ t('kinetix.token_scopes') }}</KinetixLabel>
                <label
                    v-for="(label, name) in scopes"
                    :key="name"
                    class="gap-2 text-sm flex items-center text-foreground"
                >
                    <KinetixCheckbox
                        :checked="selectedScopes.includes(String(name))"
                        @change="toggleScope(String(name), $event)"
                    />
                    <span
                        >{{ label }}
                        <span class="text-muted-foreground"
                            >({{ name }})</span
                        ></span
                    >
                </label>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                {{ t('kinetix.token_full_access') }}
            </p>

            <div class="gap-2 flex justify-end">
                <button
                    type="button"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    @click="cancelCreate"
                >
                    {{ t('kinetix.cancel') }}
                </button>
                <button
                    type="button"
                    :class="buttonVariants({ size: 'sm' })"
                    :disabled="!newName.trim()"
                    @click="save"
                >
                    {{ t('kinetix.token_create') }}
                </button>
            </div>
        </div>

        <!-- List -->
        <div
            v-if="!creating"
            class="rounded-lg divide-y divide-border border border-border bg-card"
        >
            <p
                v-if="tokens.length === 0"
                class="p-4 text-sm text-muted-foreground"
            >
                {{ t('kinetix.no_tokens') }}
            </p>

            <div v-for="token in tokens" :key="String(token.id)" class="p-3">
                <div class="gap-2 flex flex-wrap items-center justify-between">
                    <div class="min-w-0">
                        <span class="text-sm font-medium text-foreground">{{
                            token.name
                        }}</span>
                        <div class="mt-1 gap-1 flex flex-wrap">
                            <span
                                v-for="ability in token.abilities"
                                :key="ability"
                                class="px-2 py-0.5 font-mono text-xs rounded-full bg-muted text-muted-foreground"
                            >
                                {{ ability }}
                            </span>
                        </div>
                    </div>
                    <div class="gap-3 flex items-center">
                        <span class="text-xs text-muted-foreground">
                            {{ t('kinetix.token_last_used') }}:
                            {{ formatDate(token.lastUsedAt) }}
                        </span>
                        <button
                            :class="
                                buttonVariants({ variant: 'ghost', size: 'sm' })
                            "
                            @click="onDelete(token)"
                        >
                            {{ t('kinetix.token_revoke') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
