<script setup lang="ts">
import { Check, ChevronDown, Star, Trash2 } from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixSavedViews } from '@/composables/useKinetixSavedViews';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixSavedView } from '@/types/kinetix';

/**
 * Saved-views control for a table toolbar: apply / save / set-default / delete
 * presets of the current table state. The parent passes the live `currentState`
 * (what "Save current view" captures) and listens for `apply` to restore one.
 */
const props = defineProps<{
    viewKey: string;
    currentState: Record<string, unknown>;
}>();

const emit = defineEmits<{
    (e: 'apply', state: Record<string, unknown>): void;
}>();

const { t } = useI18n();
const { views, load, create, remove, setDefault } = useKinetixSavedViews(
    props.viewKey,
);

const active = ref<number | string | null>(null);

onMounted(async () => {
    await load();
    const def = views.value.find((v) => v.isDefault);

    if (def) {
        active.value = def.id;
        emit('apply', def.state);
    }
});

function apply(view: KinetixSavedView): void {
    active.value = view.id;
    emit('apply', view.state);
}

async function saveCurrent(): Promise<void> {
    const name = window.prompt(t('kinetix.saved_view_name'));

    if (!name || !name.trim()) {
        return;
    }

    try {
        await create(name.trim(), props.currentState);
        toast.success(t('kinetix.saved_view_saved'));
    } catch {
        toast.error(t('kinetix.save_failed'));
    }
}

async function onDefault(view: KinetixSavedView): Promise<void> {
    await setDefault(view);
}

async function onDelete(view: KinetixSavedView): Promise<void> {
    await remove(view);

    if (active.value === view.id) {
        active.value = null;
    }

    toast.success(t('kinetix.saved_view_deleted'));
}
</script>

<template>
    <DropdownMenuRoot>
        <DropdownMenuTrigger
            :class="buttonVariants({ variant: 'outline', size: 'sm' })"
            class="gap-1.5"
        >
            {{ t('kinetix.saved_views_label') }}
            <ChevronDown class="size-4 opacity-60" />
        </DropdownMenuTrigger>

        <DropdownMenuPortal>
            <DropdownMenuContent
                align="start"
                :side-offset="6"
                class="rounded-lg p-1 shadow-lg z-[var(--kinetix-z-popover,120)] min-w-[14rem] border border-border bg-popover outline-none"
            >
                <DropdownMenuItem
                    v-for="view in views"
                    :key="String(view.id)"
                    class="group gap-2 px-2 py-1.5 text-sm flex w-full cursor-default items-center rounded-md text-left transition-colors outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground"
                    @click="apply(view)"
                >
                    <Check
                        class="size-4 shrink-0"
                        :class="
                            active === view.id ? 'opacity-100' : 'opacity-0'
                        "
                    />
                    <span class="min-w-0 flex-1 truncate text-foreground">{{
                        view.name
                    }}</span>
                    <button
                        type="button"
                        class="shrink-0"
                        :class="
                            view.isDefault
                                ? 'text-amber-400'
                                : 'text-muted-foreground opacity-0 group-hover:opacity-100'
                        "
                        :aria-label="t('kinetix.saved_view_default')"
                        @click.stop="onDefault(view)"
                    >
                        <Star
                            class="size-3.5"
                            :class="view.isDefault ? 'fill-amber-400' : ''"
                        />
                    </button>
                    <button
                        type="button"
                        class="shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 hover:text-destructive"
                        :aria-label="t('kinetix.saved_view_delete')"
                        @click.stop="onDelete(view)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </DropdownMenuItem>

                <p
                    v-if="views.length === 0"
                    class="px-2 py-1.5 text-sm text-muted-foreground"
                >
                    {{ t('kinetix.saved_view_empty') }}
                </p>

                <DropdownMenuSeparator class="my-1 h-px bg-border" />

                <DropdownMenuItem
                    class="gap-2 px-2 py-1.5 text-sm font-medium flex w-full cursor-default items-center rounded-md text-left text-foreground transition-colors outline-none select-none hover:bg-accent focus:bg-accent"
                    @click="saveCurrent"
                >
                    {{ t('kinetix.saved_view_save_current') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
