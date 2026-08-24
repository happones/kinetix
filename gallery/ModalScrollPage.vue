<script setup lang="ts">
/**
 * Drives the Playwright E2E (scripts/e2e-modal-scroll.mjs): the same long form
 * in the three shells a host app reaches for — the modal's default (bounded
 * shell, whole panel scrolls), the modal's `scrollBody` layout (header/footer
 * pinned, body in a shadcn ScrollArea) and the sheet — so "a long form can
 * never strand its own content off screen" is asserted, not assumed.
 */
import { ref } from 'vue';
import KinetixSheet from '@/components/KinetixSheet.vue';
import KinetixModal from '@/components/primitives/KinetixModal.vue';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';

const openDefault = ref(false);
const openPinned = ref(false);
const openSheet = ref(false);

const FIELDS = Array.from({ length: 16 }, (_, i) => i + 1);
</script>

<template>
    <div class="gap-3 flex flex-wrap">
        <button
            type="button"
            data-testid="open-default"
            :class="buttonVariants()"
            @click="openDefault = true"
        >
            Open long form (default)
        </button>
        <button
            type="button"
            data-testid="open-pinned"
            :class="buttonVariants({ variant: 'outline' })"
            @click="openPinned = true"
        >
            Open long form (scrollBody)
        </button>
        <button
            type="button"
            data-testid="open-sheet"
            :class="buttonVariants({ variant: 'outline' })"
            @click="openSheet = true"
        >
            Open long form (sheet)
        </button>

        <KinetixModal
            :open="openDefault"
            title="Long form — default shell"
            description="No scrollBody: the panel outgrows the viewport and the WRAPPER scrolls."
            max-width="sm:max-w-2xl"
            @update:open="openDefault = $event"
        >
            <div class="gap-4 flex flex-col">
                <label
                    v-for="n in FIELDS"
                    :key="n"
                    class="gap-1.5 flex flex-col"
                >
                    <span class="text-sm font-medium">Field {{ n }}</span>
                    <input
                        :data-testid="`default-field-${n}`"
                        type="text"
                        class="px-3 py-2 text-sm w-full rounded-md border border-border bg-background outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </label>
            </div>

            <template #footer>
                <button
                    type="button"
                    data-testid="default-cancel"
                    :class="buttonVariants({ variant: 'outline' })"
                    @click="openDefault = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    data-testid="default-save"
                    :class="buttonVariants()"
                >
                    Save
                </button>
            </template>
        </KinetixModal>

        <KinetixModal
            :open="openPinned"
            title="Long form — scrollBody"
            description="Header and footer pinned; the body scrolls in a shadcn ScrollArea."
            max-width="sm:max-w-2xl"
            scroll-body
            @update:open="openPinned = $event"
        >
            <div class="gap-4 flex flex-col">
                <label
                    v-for="n in FIELDS"
                    :key="n"
                    class="gap-1.5 flex flex-col"
                >
                    <span class="text-sm font-medium">Field {{ n }}</span>
                    <input
                        :data-testid="`pinned-field-${n}`"
                        type="text"
                        class="px-3 py-2 text-sm w-full rounded-md border border-border bg-background outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </label>
            </div>

            <template #footer>
                <button
                    type="button"
                    data-testid="pinned-cancel"
                    :class="buttonVariants({ variant: 'outline' })"
                    @click="openPinned = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    data-testid="pinned-save"
                    :class="buttonVariants()"
                >
                    Save
                </button>
            </template>
        </KinetixModal>

        <KinetixSheet
            :open="openSheet"
            title="Long form — sheet"
            @update:open="openSheet = $event"
        >
            <div class="gap-4 flex flex-col">
                <label
                    v-for="n in FIELDS"
                    :key="n"
                    class="gap-1.5 flex flex-col"
                >
                    <span class="text-sm font-medium">Field {{ n }}</span>
                    <input
                        :data-testid="`sheet-field-${n}`"
                        type="text"
                        class="px-3 py-2 text-sm w-full rounded-md border border-border bg-background outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </label>
            </div>

            <template #footer>
                <button
                    type="submit"
                    data-testid="sheet-save"
                    :class="buttonVariants()"
                >
                    Save
                </button>
            </template>
        </KinetixSheet>
    </div>
</template>
