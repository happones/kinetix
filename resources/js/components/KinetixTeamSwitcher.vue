<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus } from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import { useKinetixTeams } from '@/composables/useKinetixTeams';
import KinetixTeamAvatar from './KinetixTeamAvatar.vue';
import KinetixTeamIndicator from './KinetixTeamIndicator.vue';
import { cn } from './primitives/cn';

/**
 * A dropdown to switch the active team. Reads the user's teams (each with a
 * ready-made switch URL) from the shared `kinetix_teams` prop and switches by
 * visiting that URL — so it works with whatever switch route your app
 * provides. Each team shows its mark on the left (logo, default logo or
 * initials) and, when `indicator` is on, a dot in its type's color on the
 * right — or the icon configured in its place.
 *
 * `variant` dresses the trigger for where it sits: `button` (a header
 * button), `sidebar` (a full-width sidebar row that keeps only the team's mark
 * when the sidebar collapses to icons) or `header` (a compact header item).
 * Its words come from `kinetix.team_switcher.labels`, so it can say "account"
 * where your app does.
 *
 * Slots: `trigger` (`{ current }`) replaces the button; `create` replaces the
 * create item, e.g. with one opening your own modal.
 */
const props = withDefaults(
    defineProps<{
        /** After switching, reopen the current page for the new team. */
        keepPage?: boolean;
        variant?: 'button' | 'sidebar' | 'header';
        side?: 'top' | 'right' | 'bottom' | 'left';
        align?: 'start' | 'center' | 'end';
    }>(),
    { keepPage: false, variant: 'button', side: 'bottom', align: 'start' },
);

const { t } = useI18n();
const { teams, current, createUrl, labels, switchTeam } = useKinetixTeams();

const choose = (team: (typeof teams.value)[number]): void =>
    switchTeam(team, { keepPage: props.keepPage });

const words = computed(() => ({
    switch: labels.value?.switch ?? t('kinetix.teams_switch'),
    select: labels.value?.select ?? t('kinetix.teams_select'),
    create: labels.value?.create ?? t('kinetix.teams_new'),
}));

const currentName = computed(() => current.value?.name ?? words.value.select);

const triggerClass = computed(() =>
    cn(
        props.variant === 'button' &&
            cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'pl-1'),
        props.variant === 'header' &&
            cn(
                buttonVariants({ variant: 'ghost', size: 'sm' }),
                'gap-2 px-1.5',
            ),
        props.variant === 'sidebar' &&
            'gap-2 p-1.5 text-sm hover:bg-sidebar-accent hover:text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground group-data-[collapsible=icon]:p-0 flex w-full items-center rounded-md text-left outline-none group-data-[collapsible=icon]:justify-center focus-visible:ring-[3px] focus-visible:ring-ring/50',
    ),
);

function createTeam(): void {
    if (createUrl.value) {
        router.visit(createUrl.value);
    }
}
</script>

<template>
    <DropdownMenuRoot>
        <DropdownMenuTrigger v-if="$slots.trigger" as-child>
            <slot name="trigger" :current="current" />
        </DropdownMenuTrigger>
        <DropdownMenuTrigger
            v-else
            data-test="team-switcher-trigger"
            :class="triggerClass"
            :aria-label="`${words.switch}: ${currentName}`"
        >
            <span class="relative inline-flex shrink-0">
                <KinetixTeamAvatar
                    :team="current"
                    :size="variant === 'sidebar' ? 'md' : 'sm'"
                />
                <!-- A collapsed sidebar keeps only the mark: the color rides on its corner. -->
                <span
                    v-if="variant === 'sidebar' && current?.accent"
                    class="-right-0.5 -bottom-0.5 size-2.5 ring-sidebar absolute hidden rounded-full ring-2 group-data-[collapsible=icon]:block"
                    :style="{ backgroundColor: current.accent }"
                    aria-hidden="true"
                />
            </span>
            <span
                class="min-w-0 font-medium truncate"
                :class="{
                    'max-w-[10rem]': variant === 'button',
                    'max-w-[120px]': variant === 'header',
                    'font-semibold flex-1 group-data-[collapsible=icon]:hidden':
                        variant === 'sidebar',
                }"
            >
                {{ currentName }}
            </span>
            <span
                v-if="current?.accent || current?.icon"
                class="inline-flex shrink-0"
                :class="{
                    'group-data-[collapsible=icon]:hidden':
                        variant === 'sidebar',
                }"
            >
                <KinetixTeamIndicator :team="current" />
            </span>
            <ChevronsUpDown
                class="size-4 shrink-0 opacity-50"
                :class="{
                    'ml-auto group-data-[collapsible=icon]:hidden':
                        variant === 'sidebar',
                }"
            />
        </DropdownMenuTrigger>

        <DropdownMenuPortal>
            <DropdownMenuContent
                :side="side"
                :align="align"
                :side-offset="6"
                :collision-padding="8"
                class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] max-h-(--reka-dropdown-menu-content-available-height) min-w-[14rem] overflow-y-auto border border-border bg-popover outline-none"
            >
                <DropdownMenuLabel
                    class="px-2 py-1.5 text-xs font-medium text-muted-foreground"
                >
                    {{ words.switch }}
                </DropdownMenuLabel>

                <DropdownMenuItem
                    v-for="team in teams"
                    :key="team.id"
                    :aria-current="team.current ? 'true' : undefined"
                    class="gap-2 px-2 py-1.5 text-sm flex w-full cursor-default items-center rounded-md text-left transition-colors outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground"
                    :class="
                        team.current
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                    @click="choose(team)"
                >
                    <KinetixTeamAvatar :team="team" size="sm" />
                    <span class="min-w-0 flex-1 truncate">{{ team.name }}</span>
                    <KinetixTeamIndicator :team="team" />
                    <!-- Held for every row, so the indicators line up. -->
                    <Check
                        class="size-4 shrink-0 text-primary"
                        :class="{ invisible: !team.current }"
                        aria-hidden="true"
                    />
                </DropdownMenuItem>

                <template v-if="$slots.create">
                    <DropdownMenuSeparator class="my-1 h-px bg-border" />
                    <slot name="create" :label="words.create" />
                </template>
                <template v-else-if="createUrl">
                    <DropdownMenuSeparator class="my-1 h-px bg-border" />
                    <DropdownMenuItem
                        data-test="team-switcher-create"
                        class="gap-2 px-2 py-2 text-sm flex w-full cursor-default items-center rounded-md text-left text-foreground transition-colors outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground"
                        @click="createTeam"
                    >
                        <Plus class="size-4" />
                        {{ words.create }}
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
