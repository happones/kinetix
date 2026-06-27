<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixSettings } from '@/composables/useKinetixSettings';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { KinetixSettingsPageData } from '@/types';
import KinetixForm from './KinetixForm.vue';

/**
 * Renders a single Kinetix settings page: its schema-driven form (reusing
 * <KinetixForm>) wired to the settings endpoint. Drop it into the host's own
 * settings layout as a tab.
 *
 * Either pass the page DTO from a controller (`:page`), or just a `page-key` and
 * the component self-loads it (like <KinetixRoleManager>) — no host controller
 * needed. Gate the screen behind the `settings.manage` ability where you mount it.
 */
const props = defineProps<{
    page?: KinetixSettingsPageData;
    pageKey?: string;
}>();

const { t } = useI18n();
const { loading, saving, load, save } = useKinetixSettings();

const current = ref<KinetixSettingsPageData | null>(props.page ?? null);

onMounted(async () => {
    if (!current.value && props.pageKey) {
        current.value = await load(props.pageKey);
    }
});

async function onSubmit(values: Record<string, unknown>): Promise<void> {
    if (!current.value) {
        return;
    }

    try {
        await save(current.value.key, values);
        toast.success(t('kinetix.settings_saved'));
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : t('kinetix.save_failed'),
        );
    }
}
</script>

<template>
    <div class="space-y-4">
        <!-- Loading skeleton while the page DTO is fetched -->
        <div v-if="loading && !current" class="space-y-4">
            <div class="h-5 w-40 animate-pulse rounded-md bg-muted" />
            <div class="h-9 animate-pulse w-full rounded-md bg-muted" />
            <div class="h-9 animate-pulse w-full rounded-md bg-muted" />
        </div>

        <template v-else-if="current">
            <h2 class="text-lg font-semibold text-foreground">
                {{ current.title }}
            </h2>

            <KinetixForm :form="current.form" @submit="onSubmit">
                <template #default>
                    <button
                        type="submit"
                        :disabled="saving"
                        :class="buttonVariants()"
                    >
                        {{ t('kinetix.save') }}
                    </button>
                </template>
            </KinetixForm>
        </template>
    </div>
</template>
