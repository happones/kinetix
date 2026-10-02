<script setup lang="ts">
import { Circle, ExternalLink, Lock } from '@lucide/vue';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import { useActionConfirmation } from '@/composables/useKinetixActions';
import { requestConfidentialUnlock } from '@/composables/useKinetixConfidential';
import { resolveIcon as resolveActionIcon } from '@/composables/useKinetixIcons';
import {
    gridColumnVars,
    resolveColumns,
    SINGLE_COLUMN,
    spanVars,
} from '@/composables/useKinetixResponsiveGrid';
import type { ResponsiveColumns } from '@/composables/useKinetixResponsiveGrid';
import {
    actionButtonVariant,
    buttonVariants,
} from '@/composables/useKinetixShadcnVariants';
import { statusTextClass } from '@/composables/useKinetixStatusColor';
import type { KinetixAction, KinetixInfolistEntry } from '@/types/kinetix';
import './kinetix-grid.css';
import KinetixActionDropdown from './KinetixActionDropdown.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';
import KinetixCopyable from './primitives/KinetixCopyable.vue';

const getTextColorClass = (color?: string | null) =>
    statusTextClass(color, 'text-foreground');
const getIconColorClass = (color?: string | null) =>
    statusTextClass(color, 'text-muted-foreground');

const props = defineProps<{
    schema: KinetixInfolistEntry[];
    /** The enclosing grid's per-breakpoint columns — spans clamp against it. */
    parentColumns?: ResponsiveColumns;
    /**
     * Chrome-free rendering for infolists hosted inside a modal: the modal
     * panel is already the surface, so Sections/Tabs drop their card
     * (border/shadow/bg) instead of nesting card-in-modal.
     */
    flat?: boolean;
}>();

const cols = computed<ResponsiveColumns>(
    () => props.parentColumns ?? SINGLE_COLUMN,
);

/** Per-breakpoint `--kx-span-*` vars for an entry/layout node. */
const colStyle = (entry: KinetixInfolistEntry): Record<string, string> =>
    spanVars(entry.columnSpan, cols.value);

/** Infolist layouts default to the fine-grained 12-column system. */
const gridOf = (entry: {
    columns?: number | Record<string, number> | null;
}): ResponsiveColumns => resolveColumns(entry.columns ?? 12);

const { t } = useI18n();

// Section header actions. Each recursive instance handles the actions of the
// sections it renders, with its own confirmation modal (only one opens at a time).
const {
    pendingAction,
    isConfirmOpen,
    processing,
    requestAction,
    confirm,
    cancel,
} = useActionConfirmation();

const sectionActionClass = (action: KinetixAction) =>
    buttonVariants({ variant: actionButtonVariant(action.color), size: 'sm' });

// Active tab index per Tabs entry, keyed by its position in this schema list.
const activeTab = reactive<Record<number, number>>({});
const currentTab = (entryIndex: number) => activeTab[entryIndex] ?? 0;
const setActiveTab = (entryIndex: number, tabIndex: number) => {
    activeTab[entryIndex] = tabIndex;
};

// Resolve through the shared Kinetix icon map (entry, section, tab & action
// icons), falling back to a neutral circle for unknown non-empty names.
const resolveIcon = (name?: string | null) =>
    name ? (resolveActionIcon(name) ?? Circle) : null;

const isEmpty = (value: unknown) =>
    value === null || value === undefined || value === '';
</script>

<template>
    <template v-for="(entry, index) in schema" :key="entry.name ?? index">
        <!-- Grid layout (host wrapper measures the grid's own width) -->
        <div
            v-if="entry.type === 'grid'"
            class="kinetix-col kinetix-grid-host"
            :style="colStyle(entry)"
        >
            <div
                class="kinetix-grid gap-4 grid"
                :style="gridColumnVars(gridOf(entry))"
            >
                <KinetixInfolistEntries
                    :schema="entry.schema || []"
                    :parent-columns="gridOf(entry)"
                    :flat="flat"
                />
            </div>
        </div>

        <!-- Section layout: a card by default; flat (divided group) when the
             host is already a surface, e.g. a modal panel. -->
        <div
            v-else-if="entry.type === 'section'"
            class="kinetix-col"
            :class="
                flat
                    ? '[&:not(:first-child)]:pt-4 [&:not(:first-child)]:border-t [&:not(:first-child)]:border-border'
                    : 'rounded-xl shadow-sm border border-border bg-card text-card-foreground'
            "
            :style="colStyle(entry)"
        >
            <div
                v-if="
                    entry.heading ||
                    entry.description ||
                    (entry.actions?.length ?? 0) > 0
                "
                :class="flat ? 'pb-4' : 'p-6 pb-4 border-b border-border'"
            >
                <div class="gap-4 flex items-start justify-between">
                    <div class="gap-3 min-w-0 flex items-start">
                        <span
                            v-if="entry.icon"
                            class="h-9 w-9 mt-0.5 rounded-lg flex shrink-0 items-center justify-center border border-border bg-muted/60"
                            aria-hidden="true"
                        >
                            <component
                                :is="resolveIcon(entry.icon)"
                                class="h-4 w-4 text-muted-foreground"
                            />
                        </span>
                        <div class="min-w-0">
                            <h3
                                v-if="entry.heading"
                                class="font-semibold tracking-tight leading-none text-foreground"
                                :class="flat ? 'text-sm' : ''"
                            >
                                {{ entry.heading }}
                            </h3>
                            <p
                                v-if="entry.description"
                                class="text-sm mt-1.5 text-muted-foreground"
                            >
                                {{ entry.description }}
                            </p>
                        </div>
                    </div>

                    <!-- Section header actions -->
                    <div
                        v-if="(entry.actions?.length ?? 0) > 0"
                        class="gap-2 flex shrink-0 items-center"
                    >
                        <template
                            v-for="(action, ai) in entry.actions"
                            :key="`sa-${ai}`"
                        >
                            <KinetixActionDropdown
                                v-if="action.type === 'group'"
                                :group="action"
                                @action-click="
                                    (a: KinetixAction) => requestAction(a)
                                "
                            />
                            <button
                                v-else
                                type="button"
                                :class="sectionActionClass(action)"
                                @click="requestAction(action)"
                            >
                                <component
                                    :is="resolveIcon(action.icon)"
                                    v-if="action.icon"
                                />
                                {{ action.label }}
                            </button>
                        </template>
                    </div>
                </div>
            </div>
            <div class="kinetix-grid-host" :class="flat ? '' : 'p-6'">
                <div
                    class="kinetix-grid gap-x-4 gap-y-5 grid"
                    :style="gridColumnVars(gridOf(entry))"
                >
                    <KinetixInfolistEntries
                        :schema="entry.schema || []"
                        :parent-columns="gridOf(entry)"
                        :flat="flat"
                    />
                </div>
            </div>
        </div>

        <!-- Fieldset layout -->
        <fieldset
            v-else-if="entry.type === 'fieldset'"
            class="kinetix-col kinetix-grid-host rounded-xl px-5 pb-5 pt-2 border border-border"
            :style="colStyle(entry)"
        >
            <legend
                v-if="entry.heading"
                class="px-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ entry.heading }}
            </legend>
            <div
                class="kinetix-grid mt-2 gap-x-4 gap-y-5 grid"
                :style="gridColumnVars(gridOf(entry))"
            >
                <KinetixInfolistEntries
                    :schema="entry.schema || []"
                    :parent-columns="gridOf(entry)"
                    :flat="flat"
                />
            </div>
        </fieldset>

        <!-- Tabs layout: card by default; chrome-free when flat. -->
        <div
            v-else-if="entry.type === 'tabs'"
            class="kinetix-col"
            :class="
                flat
                    ? ''
                    : 'rounded-xl shadow-sm border border-border bg-card text-card-foreground'
            "
            :style="colStyle(entry)"
        >
            <div
                role="tablist"
                class="gap-1 flex flex-wrap border-b border-border"
                :class="flat ? '' : 'px-2 pt-2'"
            >
                <button
                    v-for="(tab, tabIndex) in entry.schema || []"
                    :key="tabIndex"
                    type="button"
                    role="tab"
                    :aria-selected="currentTab(index) === tabIndex"
                    class="gap-1.5 px-3 py-2 text-sm font-medium inline-flex items-center rounded-t-md transition-colors"
                    :class="
                        currentTab(index) === tabIndex
                            ? 'border-b-2 border-primary text-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="setActiveTab(index, tabIndex)"
                >
                    <component
                        :is="resolveIcon(tab.icon)"
                        v-if="tab.icon"
                        class="h-4 w-4"
                    />
                    {{ tab.heading }}
                </button>
            </div>
            <div class="kinetix-grid-host" :class="flat ? 'pt-4' : 'p-6'">
                <template
                    v-for="(tab, tabIndex) in entry.schema || []"
                    :key="tabIndex"
                >
                    <div
                        v-show="currentTab(index) === tabIndex"
                        class="kinetix-grid gap-x-4 gap-y-5 grid"
                        :style="gridColumnVars(gridOf(tab))"
                    >
                        <KinetixInfolistEntries
                            :schema="tab.schema || []"
                            :parent-columns="gridOf(tab)"
                            :flat="flat"
                        />
                    </div>
                </template>
            </div>
        </div>

        <!-- Entry wrapper -->
        <div
            v-else
            :style="colStyle(entry)"
            class="kinetix-col min-w-0"
            :class="
                entry.isInline
                    ? 'gap-4 flex items-center justify-between'
                    : 'gap-1.5 flex flex-col'
            "
        >
            <span
                v-if="entry.label"
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ entry.label }}
            </span>

            <!-- Empty placeholder -->
            <span
                v-if="isEmpty(entry.state)"
                class="text-sm text-muted-foreground/70 italic"
            >
                {{ entry.placeholder ?? '—' }}
            </span>

            <!-- Icon entry -->
            <component
                :is="resolveIcon(entry.icon)"
                v-else-if="entry.type === 'icon'"
                :class="getIconColorClass(entry.color)"
                :style="{
                    width: `${entry.size || 24}px`,
                    height: `${entry.size || 24}px`,
                }"
            />

            <!-- Image entry -->
            <img
                v-else-if="entry.type === 'image'"
                :src="String(entry.state)"
                :alt="entry.label ?? ''"
                class="shadow-sm object-cover ring-1 ring-border"
                :class="entry.isCircular ? 'rounded-full' : 'rounded-lg'"
                :style="{
                    width: `${entry.size || 96}px`,
                    height: `${entry.size || 96}px`,
                }"
            />

            <!-- Color entry (copyable: swatch + code are the copy trigger) -->
            <component
                :is="entry.isCopyable ? KinetixCopyable : 'div'"
                v-else-if="entry.type === 'color'"
                v-bind="entry.isCopyable ? { value: String(entry.state) } : {}"
                class="w-fit"
            >
                <span class="gap-2 inline-flex items-center">
                    <span
                        class="h-6 w-6 shadow-sm rounded-md border border-border"
                        :style="{ backgroundColor: String(entry.state) }"
                        aria-hidden="true"
                    />
                    <span class="text-sm font-mono text-foreground">
                        {{ entry.state }}
                    </span>
                </span>
            </component>

            <!-- Badge text entry (copyable: the pill is the copy trigger) -->
            <component
                :is="entry.isCopyable ? KinetixCopyable : 'span'"
                v-else-if="entry.type === 'text' && entry.isBadge"
                v-bind="entry.isCopyable ? { value: String(entry.state) } : {}"
                class="w-fit"
            >
                <KinetixBadge :color="entry.color" class="gap-1">
                    <component
                        :is="resolveIcon(entry.icon)"
                        v-if="entry.icon"
                        class="h-3 w-3"
                    />
                    {{ entry.state }}
                </KinetixBadge>
            </component>

            <!-- Linked text entry (the link keeps its click; copy sits beside it) -->
            <div
                v-else-if="entry.type === 'text' && entry.url"
                class="gap-1 flex items-center"
            >
                <a
                    :href="entry.url"
                    :target="entry.openUrlInNewTab ? '_blank' : undefined"
                    :rel="
                        entry.openUrlInNewTab
                            ? 'noopener noreferrer'
                            : undefined
                    "
                    class="gap-1 text-sm font-medium inline-flex w-fit items-center text-info hover:underline"
                >
                    <component
                        :is="resolveIcon(entry.icon)"
                        v-if="entry.icon"
                        class="h-3.5 w-3.5"
                    />
                    {{ entry.state }}
                    <ExternalLink
                        v-if="entry.openUrlInNewTab"
                        class="h-3 w-3"
                    />
                </a>
                <KinetixCopyable
                    v-if="entry.isCopyable"
                    :value="String(entry.state)"
                />
            </div>

            <!-- Plain text entry (copyable: the value is the copy trigger) -->
            <div v-else class="gap-2 flex items-center">
                <component
                    :is="resolveIcon(entry.icon)"
                    v-if="entry.icon"
                    class="h-3.5 w-3.5"
                    :class="getIconColorClass(entry.color)"
                />
                <component
                    :is="entry.isCopyable ? KinetixCopyable : 'span'"
                    v-bind="
                        entry.isCopyable ? { value: String(entry.state) } : {}
                    "
                    class="min-w-0"
                >
                    <span
                        class="text-sm font-medium leading-relaxed min-w-0 break-words"
                        :class="getTextColorClass(entry.color)"
                    >
                        {{ entry.state }}
                    </span>
                </component>
                <button
                    v-if="entry.isConfidential"
                    type="button"
                    class="text-muted-foreground transition-colors hover:text-foreground"
                    :title="t('kinetix.confidential_unlock')"
                    @click="requestConfidentialUnlock()"
                >
                    <Lock class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    </template>

    <!-- Confirmation modal for section actions that require it. -->
    <KinetixConfirmModal
        v-model:open="isConfirmOpen"
        :heading="pendingAction?.modalHeading"
        :description="pendingAction?.modalDescription"
        :icon="pendingAction?.modalIcon"
        :color="pendingAction?.color"
        :submit-label="pendingAction?.modalSubmitActionLabel"
        :cancel-label="pendingAction?.modalCancelActionLabel"
        :processing="processing"
        @confirm="confirm"
        @cancel="cancel"
    />
</template>
