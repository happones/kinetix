<script setup lang="ts">
import {
    Copy,
    Hand,
    MessageCircle,
    MessagesSquare,
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Search,
    Trash2,
    Workflow,
} from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixFlows } from '@/composables/useKinetixFlows';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import type { KinetixFlow } from '@/types/kinetix';
import KinetixButton from './KinetixButton.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixLabel from './KinetixLabel.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';
import KinetixModal from './primitives/KinetixModal.vue';

/**
 * The team's bot flows: what starts each one, where it runs, whether it's
 * live (and has unpublished changes), how many conversations it's handled.
 * Create one, open it in the editor (`@edit`), turn it on or off,
 * duplicate, delete. Mount behind `flows.manage`.
 */
const emit = defineEmits<{
    (e: 'edit', flow: { id: number | string }): void;
}>();

const { t } = useI18n();
const {
    flows,
    lines,
    loaded,
    load,
    create,
    activate,
    deactivate,
    duplicate,
    remove,
} = useKinetixFlows();

const creating = ref(false);
const newName = ref('');
const processing = ref(false);
const deleting = ref<KinetixFlow | null>(null);

onMounted(load);

const triggerIcon = {
    keyword: Search,
    first_message: MessageCircle,
    any_message: MessagesSquare,
    manual: Hand,
};

function triggerText(flow: KinetixFlow): string {
    if (flow.trigger.type === 'keyword') {
        const keywords = flow.trigger.keywords ?? [];

        return keywords.length
            ? t('kinetix.flow_trigger_keyword_list', {
                  keywords:
                      keywords.slice(0, 3).join(', ') +
                      (keywords.length > 3 ? '…' : ''),
              })
            : t('kinetix.flow_trigger_keyword_none');
    }

    return t(`kinetix.flow_trigger_${flow.trigger.type}`);
}

function linesText(flow: KinetixFlow): string {
    if (!flow.channelIds?.length) {
        return t('kinetix.flow_lines_all');
    }

    return lines.value
        .filter((line) =>
            flow.channelIds?.map(String).includes(String(line.id)),
        )
        .map((line) => line.name)
        .join(', ');
}

const statusColor = (flow: KinetixFlow): string =>
    ({ active: 'success', draft: 'gray', inactive: 'warning' })[flow.status];

const sorted = computed(() =>
    [...flows.value].sort(
        (a, b) => Number(b.status === 'active') - Number(a.status === 'active'),
    ),
);

async function run(
    work: () => Promise<unknown>,
    success?: string,
): Promise<void> {
    processing.value = true;

    try {
        await work();
        await load();

        if (success) {
            toast.success(success);
        }
    } catch (error) {
        toast.error(error instanceof Error ? error.message : String(error));
    } finally {
        processing.value = false;
    }
}

async function submitCreate(): Promise<void> {
    processing.value = true;

    try {
        const flow = await create(newName.value);

        if (flow) {
            creating.value = false;
            newName.value = '';
            emit('edit', flow);
        }
    } catch (error) {
        toast.error(error instanceof Error ? error.message : String(error));
    } finally {
        processing.value = false;
    }
}

const itemClass =
    'gap-2 px-2 py-1.5 text-sm flex w-full cursor-default items-center rounded-md outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50';
</script>

<template>
    <section class="space-y-4" data-test="flow-list">
        <header
            class="gap-3 sm:flex-row sm:items-center flex flex-col justify-between"
        >
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-foreground">
                    {{ t('kinetix.flows_title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('kinetix.flows_description') }}
                </p>
            </div>
            <KinetixButton
                size="sm"
                data-test="flow-create"
                @click="creating = true"
            >
                <Plus class="size-4" />
                {{ t('kinetix.flow_create') }}
            </KinetixButton>
        </header>

        <ul v-if="!loaded" class="space-y-2" aria-busy="true">
            <li
                v-for="n in 3"
                :key="n"
                class="h-20 rounded-xl motion-safe:animate-pulse border border-border bg-muted/40"
            />
        </ul>

        <div
            v-else-if="flows.length === 0"
            class="rounded-xl px-6 py-12 flex flex-col items-center border border-dashed border-border text-center"
        >
            <span
                class="mb-4 size-12 flex items-center justify-center rounded-full bg-muted"
            >
                <Workflow class="size-6 text-muted-foreground" />
            </span>
            <h3 class="text-sm font-semibold text-foreground">
                {{ t('kinetix.flows_empty_title') }}
            </h3>
            <p class="mt-1 text-sm max-w-sm text-muted-foreground">
                {{ t('kinetix.flows_empty_description') }}
            </p>
        </div>

        <ul
            v-else
            class="rounded-xl divide-y divide-border border border-border bg-card"
        >
            <li
                v-for="flow in sorted"
                :key="flow.id"
                class="gap-3 p-4 sm:flex-row sm:items-center flex flex-col"
                :data-test="`flow-${flow.id}`"
            >
                <button
                    type="button"
                    class="gap-3 min-w-0 flex flex-1 items-start rounded-md text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @click="emit('edit', flow)"
                >
                    <span
                        class="size-10 rounded-lg inline-flex shrink-0 items-center justify-center bg-primary/10 text-primary"
                    >
                        <component
                            :is="triggerIcon[flow.trigger.type]"
                            class="size-5"
                        />
                    </span>
                    <span class="min-w-0">
                        <span class="gap-2 flex flex-wrap items-center">
                            <span
                                class="font-medium truncate text-foreground"
                                >{{ flow.name }}</span
                            >
                            <KinetixBadge :color="statusColor(flow)">
                                {{ t(`kinetix.flow_status_${flow.status}`) }}
                                <template v-if="flow.version"
                                    >· v{{ flow.version }}</template
                                >
                            </KinetixBadge>
                            <span
                                v-if="flow.unpublished && flow.version"
                                class="text-xs text-warning"
                                >{{ t('kinetix.flow_unpublished') }}</span
                            >
                        </span>
                        <span
                            class="text-sm block truncate text-muted-foreground"
                        >
                            {{ triggerText(flow) }} · {{ linesText(flow) }}
                        </span>
                    </span>
                </button>

                <div
                    class="gap-3 sm:justify-end flex shrink-0 items-center justify-between"
                >
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{ t('kinetix.flow_runs', { count: flow.runs }) }}
                        <template v-if="flow.activeRuns">
                            ·
                            {{
                                t('kinetix.flow_active_runs', {
                                    count: flow.activeRuns,
                                })
                            }}
                        </template>
                    </span>

                    <DropdownMenuRoot>
                        <DropdownMenuTrigger
                            :class="
                                buttonVariants({
                                    variant: 'ghost',
                                    size: 'icon-sm',
                                })
                            "
                            :aria-label="
                                t('kinetix.channel_actions', {
                                    name: flow.name,
                                })
                            "
                        >
                            <MoreHorizontal class="size-4" />
                        </DropdownMenuTrigger>
                        <DropdownMenuPortal>
                            <DropdownMenuContent
                                align="end"
                                :side-offset="4"
                                :collision-padding="8"
                                class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] min-w-[12rem] border border-border bg-popover text-popover-foreground"
                            >
                                <DropdownMenuItem
                                    :class="itemClass"
                                    @select="emit('edit', flow)"
                                >
                                    <Pencil class="size-4" />
                                    {{ t('kinetix.edit') }}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="flow.status === 'active'"
                                    :class="itemClass"
                                    @select="
                                        run(
                                            () => deactivate(flow.id),
                                            t('kinetix.flow_deactivated'),
                                        )
                                    "
                                >
                                    <PowerOff class="size-4" />
                                    {{ t('kinetix.flow_deactivate') }}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-else-if="flow.version"
                                    :class="itemClass"
                                    @select="
                                        run(
                                            () => activate(flow.id),
                                            t('kinetix.flow_activated'),
                                        )
                                    "
                                >
                                    <Power class="size-4" />
                                    {{ t('kinetix.flow_activate') }}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    :class="itemClass"
                                    @select="run(() => duplicate(flow.id))"
                                >
                                    <Copy class="size-4" />
                                    {{ t('kinetix.flow_duplicate') }}
                                </DropdownMenuItem>
                                <DropdownMenuSeparator
                                    class="my-1 h-px bg-border"
                                />
                                <DropdownMenuItem
                                    :class="[
                                        itemClass,
                                        'text-destructive focus:text-destructive',
                                    ]"
                                    @select="deleting = flow"
                                >
                                    <Trash2 class="size-4" />
                                    {{ t('kinetix.delete') }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenuPortal>
                    </DropdownMenuRoot>
                </div>
            </li>
        </ul>

        <KinetixModal
            :open="creating"
            :title="t('kinetix.flow_create')"
            :description="t('kinetix.flow_create_description')"
            :processing="processing"
            max-width="sm:max-w-md"
            @update:open="creating = $event"
        >
            <form
                id="kinetix-flow-create"
                class="space-y-2"
                @submit.prevent="submitCreate"
            >
                <KinetixLabel for="kinetix-flow-name">{{
                    t('kinetix.flow_name')
                }}</KinetixLabel>
                <input
                    id="kinetix-flow-name"
                    v-model="newName"
                    required
                    maxlength="100"
                    :class="inputClass"
                    :placeholder="t('kinetix.flow_name_placeholder')"
                    data-test="flow-create-name"
                />
            </form>
            <template #footer>
                <KinetixButton
                    variant="outline"
                    :disabled="processing"
                    @click="creating = false"
                >
                    {{ t('kinetix.cancel') }}
                </KinetixButton>
                <KinetixButton
                    type="submit"
                    form="kinetix-flow-create"
                    :loading="processing"
                    data-test="flow-create-submit"
                >
                    {{ t('kinetix.flow_create') }}
                </KinetixButton>
            </template>
        </KinetixModal>

        <KinetixConfirmModal
            :open="deleting !== null"
            :heading="t('kinetix.flow_delete_heading')"
            :description="
                t('kinetix.flow_delete_confirm', { name: deleting?.name ?? '' })
            "
            :submit-label="t('kinetix.delete')"
            color="danger"
            :processing="processing"
            @update:open="deleting = $event ? deleting : null"
            @confirm="
                run(async () => {
                    await remove(deleting!.id);
                    deleting = null;
                }, t('kinetix.deleted'))
            "
        />
    </section>
</template>
