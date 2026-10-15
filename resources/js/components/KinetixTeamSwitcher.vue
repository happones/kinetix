<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus, Users } from '@lucide/vue';
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

/**
 * A header dropdown to switch the active team. Reads the user's teams (each with
 * a ready-made switch URL) from the shared `kinetix_teams` prop and switches by
 * visiting that URL — so it works with whatever switch route your app provides.
 *
 * Slots: `trigger` (`{ current }`) replaces the button, e.g. with a sidebar
 * menu button; `create` replaces the "New team" item, e.g. with one opening
 * your own modal.
 */
const props = withDefaults(
    defineProps<{
        /** After switching, reopen the current page for the new team. */
        keepPage?: boolean;
        side?: 'top' | 'right' | 'bottom' | 'left';
        align?: 'start' | 'center' | 'end';
    }>(),
    { keepPage: false, side: 'bottom', align: 'start' },
);

const { t } = useI18n();
const { teams, current, createUrl, switchTeam } = useKinetixTeams();

const choose = (team: (typeof teams.value)[number]): void =>
    switchTeam(team, { keepPage: props.keepPage });

const currentName = computed(
    () => current.value?.name ?? t('kinetix.teams_select'),
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
            :class="buttonVariants({ variant: 'outline', size: 'sm' })"
            :aria-label="t('kinetix.teams_switch')"
        >
            <Users class="size-4 opacity-70" />
            <span class="max-w-[10rem] truncate">{{ currentName }}</span>
            <ChevronsUpDown class="size-4 opacity-50" />
        </DropdownMenuTrigger>

        <DropdownMenuPortal>
            <DropdownMenuContent
                :side="side"
                :align="align"
                :side-offset="6"
                class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] min-w-[12rem] border border-border bg-popover outline-none"
            >
                <DropdownMenuLabel
                    class="px-2 py-1.5 text-xs font-medium text-muted-foreground"
                >
                    {{ t('kinetix.teams_switch') }}
                </DropdownMenuLabel>

                <DropdownMenuItem
                    v-for="team in teams"
                    :key="team.id"
                    class="gap-2 px-2 py-2 text-sm flex w-full cursor-default items-center justify-between rounded-md text-left transition-colors outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground"
                    :class="
                        team.current
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                    @click="choose(team)"
                >
                    <span class="truncate">{{ team.name }}</span>
                    <Check
                        v-if="team.current"
                        class="size-4 shrink-0 text-primary"
                    />
                </DropdownMenuItem>

                <template v-if="$slots.create">
                    <DropdownMenuSeparator class="my-1 h-px bg-border" />
                    <slot name="create" />
                </template>
                <template v-else-if="createUrl">
                    <DropdownMenuSeparator class="my-1 h-px bg-border" />
                    <DropdownMenuItem
                        class="gap-2 px-2 py-2 text-sm flex w-full cursor-default items-center rounded-md text-left text-foreground transition-colors outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground"
                        @click="createTeam"
                    >
                        <Plus class="size-4" />
                        {{ t('kinetix.teams_new') }}
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
