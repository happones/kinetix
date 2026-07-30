<script setup lang="ts">
import { SlidersHorizontal } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { KinetixTableColumn } from '@/types/kinetix';
import KinetixCheckbox from '../KinetixCheckbox.vue';

const props = defineProps<{
    columns: KinetixTableColumn[];
    isColumnVisible: (name: string) => boolean;
}>();

const emit = defineEmits<{
    (e: 'toggle', name: string): void;
}>();

const { t } = useI18n();

const open = ref(false);

const toggleableColumns = computed<KinetixTableColumn[]>(() =>
    props.columns.filter((c) => c.isToggleable),
);
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <button :class="buttonVariants({ variant: 'outline', size: 'sm' })">
                <SlidersHorizontal class="h-3.5 w-3.5" />
                {{ t('kinetix.columns') }}
            </button>
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="4"
                class="w-56 rounded-lg p-3 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 border border-border bg-popover outline-none"
            >
                <div
                    class="text-xs font-bold pb-2 mb-2 tracking-wider border-b border-border text-foreground uppercase"
                >
                    {{ t('kinetix.toggle_columns') }}
                </div>
                <div class="space-y-2 max-h-60 overflow-y-auto">
                    <div
                        v-for="col in toggleableColumns"
                        :key="col.name"
                        class="gap-2 py-0.5 rounded px-1.5 flex items-center hover:bg-accent"
                    >
                        <KinetixCheckbox
                            :id="'col-' + col.name"
                            :checked="isColumnVisible(col.name)"
                            @change="emit('toggle', col.name)"
                        />
                        <label
                            :for="'col-' + col.name"
                            class="text-xs py-1 flex-1 cursor-pointer text-foreground select-none"
                        >
                            {{ col.label }}
                        </label>
                    </div>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
