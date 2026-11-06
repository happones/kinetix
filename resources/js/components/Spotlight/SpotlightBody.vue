<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ComboboxContent,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxRoot,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    VisuallyHidden,
} from 'reka-ui';
import { onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixFailureMessage } from '@/composables/kinetixThrottle';
import { useKinetixSpotlight } from '@/composables/useKinetixSpotlight';
import type {
    KinetixSpotlightGroup,
    KinetixSpotlightItem,
} from '@/types/kinetix';

/**
 * The command palette itself, loaded by `<KinetixSpotlight>` on the first
 * Cmd/Ctrl+K (or `kinetix:spotlight` event). Built on Reka Dialog + Combobox
 * (keyboard nav + selection for free). Searches the spotlight endpoint
 * (debounced); server results are already authorization-filtered. The shell
 * owns the shortcut and the event; this body only follows `v-model:open`.
 */
const { t } = useI18n();
const { loading, minChars, search } = useKinetixSpotlight();

const open = defineModel<boolean>('open', { default: false });
const query = ref('');
const groups = ref<KinetixSpotlightGroup[]>([]);
/** Why the last search failed (the server's message for a throttle), or null. */
const failure = ref<string | null>(null);

let debounce: ReturnType<typeof setTimeout> | undefined;

onBeforeUnmount(() => clearTimeout(debounce));

function onOpenChange(value: boolean): void {
    open.value = value;

    if (!value) {
        query.value = '';
        groups.value = [];
        failure.value = null;
    }
}

function onInput(event: Event): void {
    query.value = (event.target as HTMLInputElement).value;
    clearTimeout(debounce);
    failure.value = null;

    // Below the threshold there is nothing worth fanning out for: `%a%` matches
    // nearly every row of every source. The endpoint enforces the same floor.
    if (query.value.length < minChars.value) {
        groups.value = [];

        return;
    }

    debounce = setTimeout(() => void runSearch(query.value), 200);
}

async function runSearch(term: string): Promise<void> {
    try {
        const result = await search(term);

        // `null` = this search was superseded by a newer keystroke. Painting it
        // would show results for a query the reader already replaced.
        if (result !== null) {
            groups.value = result;
        }
    } catch (error) {
        // A newer keystroke owns the palette now: its own search decides.
        if (term !== query.value) {
            return;
        }

        // The results on screen answer an older query: drop them, and say why
        // (a throttle says when to try again).
        groups.value = [];
        failure.value = kinetixFailureMessage(
            error,
            t('kinetix.action_failed'),
        );
    }
}

function onSelect(item: unknown): void {
    const selected = item as KinetixSpotlightItem | undefined;

    if (!selected) {
        return;
    }

    onOpenChange(false);

    if (selected.url) {
        router.visit(selected.url);
    } else if (selected.event) {
        window.dispatchEvent(
            new CustomEvent(selected.event, { detail: selected }),
        );
    }
}
</script>

<template>
    <DialogRoot :open="open" @update:open="onOpenChange">
        <DialogPortal>
            <DialogOverlay
                class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 inset-0 bg-black/80 fixed z-[var(--kinetix-z-overlay,100)]"
            />
            <DialogContent
                class="top-24 max-w-lg rounded-lg shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed left-1/2 z-[var(--kinetix-z-modal,100)] w-full -translate-x-1/2 overflow-hidden border border-border bg-popover text-popover-foreground duration-200"
            >
                <VisuallyHidden>
                    <DialogTitle>{{
                        t('kinetix.spotlight_placeholder')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('kinetix.spotlight_placeholder')
                    }}</DialogDescription>
                </VisuallyHidden>

                <ComboboxRoot
                    :open="true"
                    :ignore-filter="true"
                    @update:model-value="onSelect"
                >
                    <ComboboxInput
                        auto-focus
                        :placeholder="t('kinetix.spotlight_placeholder')"
                        class="px-4 py-3 text-sm w-full border-b border-border bg-transparent text-foreground outline-none placeholder:text-muted-foreground"
                        @input="onInput"
                    />

                    <ComboboxContent class="max-h-80 p-2 overflow-y-auto">
                        <!-- A fan-out across many sources is not instant, and
                             the empty state is a lie while it is running. -->
                        <div
                            v-if="loading"
                            class="px-2 py-6 text-sm text-center text-muted-foreground"
                            role="status"
                            aria-live="polite"
                        >
                            {{ t('kinetix.spotlight_searching') }}
                        </div>
                        <div
                            v-else-if="failure"
                            data-test="spotlight-failure"
                            class="px-2 py-6 text-sm text-center text-destructive"
                            role="alert"
                        >
                            {{ failure }}
                        </div>
                        <div
                            v-else-if="query.length < minChars"
                            class="px-2 py-6 text-sm text-center text-muted-foreground"
                        >
                            {{
                                t('kinetix.spotlight_min_chars', {
                                    count: minChars,
                                })
                            }}
                        </div>
                        <ComboboxEmpty
                            v-else
                            class="px-2 py-6 text-sm text-center text-muted-foreground"
                        >
                            {{ t('kinetix.spotlight_empty') }}
                        </ComboboxEmpty>

                        <ComboboxGroup
                            v-for="group in groups"
                            :key="group.label"
                            class="mb-1"
                        >
                            <div
                                class="px-2 py-1 text-xs font-medium text-muted-foreground"
                            >
                                {{ group.label }}
                            </div>

                            <ComboboxItem
                                v-for="(item, i) in group.items"
                                :key="`${group.label}-${item.id ?? i}`"
                                :value="item"
                                class="gap-0.5 px-2 py-2 text-sm flex cursor-default flex-col rounded-md text-foreground outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
                            >
                                <span>{{ item.title }}</span>
                                <span
                                    v-if="item.subtitle"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ item.subtitle }}
                                </span>
                            </ComboboxItem>
                        </ComboboxGroup>
                    </ComboboxContent>
                </ComboboxRoot>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
