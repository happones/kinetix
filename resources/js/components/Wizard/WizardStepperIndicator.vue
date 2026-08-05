<script setup lang="ts">
import { Check } from '@lucide/vue';
import {
    StepperDescription,
    StepperIndicator,
    StepperItem,
    StepperRoot,
    StepperSeparator,
    StepperTitle,
    StepperTrigger,
    TooltipArrow,
    TooltipContent,
    TooltipPortal,
    TooltipProvider,
    TooltipRoot,
    TooltipTrigger,
} from 'reka-ui';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { useKinetixWizardStatus } from '@/composables/useKinetixWizardStatus';
import type {
    KinetixWizardStep,
    KinetixWizardStepLayout,
} from '@/types/kinetix';

const props = defineProps<{
    steps: KinetixWizardStep[];
    current: number;
    maxReached: number;
    errorSteps: number[];
    linear: boolean;
    fullWidth: boolean;
    stepLayout: KinetixWizardStepLayout;
    orientation: 'horizontal' | 'vertical';
}>();

const emit = defineEmits<{ (e: 'goto', index: number): void }>();

const { statusOf, stepDisabled, indicatorClass, stepKey } =
    useKinetixWizardStatus({
        current: () => props.current,
        maxReached: () => props.maxReached,
        errorSteps: () => props.errorSteps,
        linear: () => props.linear,
    });
</script>

<template>
    <!-- Horizontal: own scroll container so a long strip scrolls internally. -->
    <div v-if="orientation === 'horizontal'" class="overflow-x-auto">
        <TooltipProvider :disable-hoverable-content="true">
            <StepperRoot
                :model-value="current + 1"
                orientation="horizontal"
                class="flex"
                :class="
                    fullWidth
                        ? 'mb-6 gap-2 w-full items-center'
                        : 'mb-6 gap-2 mx-auto w-fit items-center'
                "
            >
                <StepperItem
                    v-for="(s, i) in steps"
                    :key="stepKey(s, i)"
                    :step="i + 1"
                    :disabled="stepDisabled(i)"
                    class="group min-w-0 flex disabled:pointer-events-none disabled:opacity-50"
                    :class="[
                        fullWidth ? 'flex-1 last:flex-none' : 'shrink-0',
                        stepLayout === 'stacked'
                            ? 'gap-2 items-start'
                            : 'gap-2 items-center',
                    ]"
                >
                    <!-- inline: indicator + label side by side, label hidden below sm: -->
                    <StepperTrigger
                        v-if="stepLayout === 'inline'"
                        as-child
                        class="gap-3 min-w-0 flex items-center"
                        @click="emit('goto', i)"
                    >
                        <button
                            type="button"
                            class="gap-3 min-w-0 flex items-center"
                        >
                            <StepperIndicator
                                class="size-9 text-sm font-semibold flex shrink-0 items-center justify-center rounded-full transition-colors"
                                :class="indicatorClass(s, i)"
                            >
                                <Check
                                    v-if="statusOf(i) === 'complete'"
                                    class="size-4"
                                />
                                <component
                                    :is="resolveIcon(s.icon)"
                                    v-else-if="resolveIcon(s.icon)"
                                    class="size-4"
                                />
                                <template v-else>{{ i + 1 }}</template>
                            </StepperIndicator>
                            <span class="sm:block min-w-0 hidden text-left">
                                <StepperTitle
                                    class="text-sm font-medium block truncate text-foreground"
                                    >{{ s.label }}</StepperTitle
                                >
                                <StepperDescription
                                    v-if="s.description"
                                    class="text-xs block truncate text-muted-foreground"
                                    >{{ s.description }}</StepperDescription
                                >
                            </span>
                        </button>
                    </StepperTrigger>

                    <!-- stacked: indicator on top, label centered below, always visible -->
                    <StepperTrigger
                        v-else-if="stepLayout === 'stacked'"
                        as-child
                        class="gap-1.5 min-w-0 flex flex-col items-center"
                        @click="emit('goto', i)"
                    >
                        <button
                            type="button"
                            class="gap-1.5 min-w-0 flex flex-col items-center"
                        >
                            <StepperIndicator
                                class="size-9 text-sm font-semibold flex shrink-0 items-center justify-center rounded-full transition-colors"
                                :class="indicatorClass(s, i)"
                            >
                                <Check
                                    v-if="statusOf(i) === 'complete'"
                                    class="size-4"
                                />
                                <component
                                    :is="resolveIcon(s.icon)"
                                    v-else-if="resolveIcon(s.icon)"
                                    class="size-4"
                                />
                                <template v-else>{{ i + 1 }}</template>
                            </StepperIndicator>
                            <span class="min-w-0 max-w-full text-center">
                                <StepperTitle
                                    class="text-xs font-medium block truncate text-foreground"
                                    >{{ s.label }}</StepperTitle
                                >
                                <StepperDescription
                                    v-if="s.description"
                                    class="block truncate text-[11px] text-muted-foreground"
                                    >{{ s.description }}</StepperDescription
                                >
                            </span>
                        </button>
                    </StepperTrigger>

                    <!-- tooltip: indicator only, label on hover/focus -->
                    <TooltipRoot v-else>
                        <TooltipTrigger as-child>
                            <StepperTrigger as-child @click="emit('goto', i)">
                                <button
                                    type="button"
                                    :aria-label="
                                        s.description
                                            ? `${s.label}: ${s.description}`
                                            : s.label
                                    "
                                >
                                    <StepperIndicator
                                        class="size-9 text-sm font-semibold flex shrink-0 items-center justify-center rounded-full transition-colors"
                                        :class="indicatorClass(s, i)"
                                    >
                                        <Check
                                            v-if="statusOf(i) === 'complete'"
                                            class="size-4"
                                        />
                                        <component
                                            :is="resolveIcon(s.icon)"
                                            v-else-if="resolveIcon(s.icon)"
                                            class="size-4"
                                        />
                                        <template v-else>{{ i + 1 }}</template>
                                    </StepperIndicator>
                                </button>
                            </StepperTrigger>
                        </TooltipTrigger>
                        <TooltipPortal>
                            <TooltipContent
                                :side-offset="6"
                                class="px-3 py-1.5 text-sm shadow-md data-[state=closed]:animate-out data-[state=open]:animate-in data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 z-[var(--kinetix-z-popover,120)] max-w-[16rem] rounded-md border border-border bg-popover text-popover-foreground"
                            >
                                <p class="font-medium">{{ s.label }}</p>
                                <p
                                    v-if="s.description"
                                    class="text-muted-foreground"
                                >
                                    {{ s.description }}
                                </p>
                                <TooltipArrow class="fill-popover" />
                            </TooltipContent>
                        </TooltipPortal>
                    </TooltipRoot>

                    <StepperSeparator
                        v-if="i < steps.length - 1"
                        class="h-0.5 shrink-0 rounded-full bg-border group-data-[state=completed]:bg-primary"
                        :class="[
                            fullWidth ? 'flex-1' : 'w-10',
                            stepLayout === 'stacked' ? 'mt-[18px]' : '',
                        ]"
                    />
                </StepperItem>
            </StepperRoot>
        </TooltipProvider>
    </div>

    <!-- Vertical: fixed-width column, wraps text within its own width. -->
    <StepperRoot
        v-else
        :model-value="current + 1"
        orientation="vertical"
        class="gap-0 md:w-64 flex shrink-0 flex-col"
    >
        <StepperItem
            v-for="(s, i) in steps"
            :key="stepKey(s, i)"
            :step="i + 1"
            :disabled="stepDisabled(i)"
            class="group gap-3 flex disabled:pointer-events-none disabled:opacity-50"
        >
            <div class="flex flex-col items-center self-stretch">
                <StepperTrigger as-child @click="emit('goto', i)">
                    <button type="button">
                        <StepperIndicator
                            class="size-9 text-sm font-semibold flex shrink-0 items-center justify-center rounded-full transition-colors"
                            :class="indicatorClass(s, i)"
                        >
                            <Check
                                v-if="statusOf(i) === 'complete'"
                                class="size-4"
                            />
                            <component
                                :is="resolveIcon(s.icon)"
                                v-else-if="resolveIcon(s.icon)"
                                class="size-4"
                            />
                            <template v-else>{{ i + 1 }}</template>
                        </StepperIndicator>
                    </button>
                </StepperTrigger>
                <StepperSeparator
                    v-if="i < steps.length - 1"
                    class="my-1 w-0.5 grow rounded-full bg-border group-data-[state=completed]:bg-primary"
                />
            </div>

            <StepperTrigger as-child @click="emit('goto', i)">
                <button type="button" class="min-w-0 pb-6 text-left">
                    <StepperTitle
                        class="text-sm font-medium block text-foreground"
                        >{{ s.label }}</StepperTitle
                    >
                    <StepperDescription
                        v-if="s.description"
                        class="text-xs block text-muted-foreground"
                        >{{ s.description }}</StepperDescription
                    >
                </button>
            </StepperTrigger>
        </StepperItem>
    </StepperRoot>
</template>
