<script setup lang="ts">
import { Loader2, X } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixNotificationRecipients } from '@/types/kinetix';
import KinetixAlert from '../KinetixAlert.vue';
import KinetixButton from '../KinetixButton.vue';
import KinetixNotificationChannelIcons from './KinetixNotificationChannelIcons.vue';

/**
 * The dry run of a notification type's rules: who would be notified and on
 * which channels, and who is left out and why. Nothing is sent. The panel is a
 * polite live region, so the result is announced when it arrives.
 */
const props = defineProps<{
    /** The type's label. */
    title: string;
    preview: KinetixNotificationRecipients | null;
    loading: boolean;
    failed: boolean;
    /** Channel key → label. */
    channelLabels: Record<string, string>;
    /** The type's own description of its default recipients, already translated. */
    fallback?: string | null;
    /** The app delivers to default recipients when no rule applies. */
    hasFallback?: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const { t } = useI18n();

/** Everyone left out; `skipped` lists only a sample of them. */
const skippedTotal = computed(
    () => props.preview?.skippedCount ?? props.preview?.skipped.length ?? 0,
);

const summary = computed(() =>
    props.preview
        ? t('kinetix.notification_routing_preview_summary', {
              notified: props.preview.recipients.length,
              skipped: skippedTotal.value,
          })
        : '',
);

/** No rule applied and the app's default recipients were used. */
const fellBack = computed(() => props.preview?.fellBack === true);

/** Nothing chosen and no default: there is no list to show, only an explanation. */
const unrouted = computed(
    () => props.preview !== null && !props.preview.hasRules && !fellBack.value,
);

const reasonLabel = (reason: string | null): string =>
    reason ? t(`kinetix.notification_routing_reason_${reason}`) : '';
</script>

<template>
    <section
        class="space-y-3 px-4 py-3 border-t border-border bg-muted/30"
        :aria-label="
            t('kinetix.notification_routing_preview_title', { type: title })
        "
        data-test="notification-routing-preview"
    >
        <div class="gap-2 flex items-start justify-between">
            <h4 class="text-sm font-medium text-foreground">
                {{
                    t('kinetix.notification_routing_preview_title', {
                        type: title,
                    })
                }}
            </h4>
            <KinetixButton
                variant="ghost"
                size="icon-sm"
                :aria-label="t('kinetix.notification_routing_hide_preview')"
                @click="emit('close')"
            >
                <X class="size-4" aria-hidden="true" />
            </KinetixButton>
        </div>

        <div aria-live="polite" aria-atomic="false" class="space-y-3">
            <p
                v-if="loading"
                class="gap-2 text-sm flex items-center text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin" aria-hidden="true" />
                {{ t('kinetix.notification_routing_preview_loading') }}
            </p>

            <p v-else-if="failed" class="text-sm text-destructive" role="alert">
                {{ t('kinetix.notification_routing_preview_failed') }}
            </p>

            <template v-else-if="preview">
                <p class="sr-only">{{ summary }}</p>

                <div
                    v-if="fellBack"
                    class="space-y-1 text-sm text-muted-foreground"
                    data-test="preview-fallback"
                >
                    <p>
                        {{ t('kinetix.notification_routing_preview_fallback') }}
                    </p>
                    <p v-if="fallback">{{ fallback }}</p>
                </div>

                <KinetixAlert
                    v-else-if="unrouted && hasFallback"
                    color="warning"
                    :description="
                        [
                            t('kinetix.notification_routing_preview_nobody'),
                            fallback,
                        ]
                            .filter(Boolean)
                            .join(' ')
                    "
                    data-test="preview-fallback-empty"
                />

                <p
                    v-else-if="unrouted"
                    class="text-sm text-muted-foreground"
                    data-test="preview-no-rules"
                >
                    {{
                        fallback ||
                        t('kinetix.notification_routing_preview_no_rules')
                    }}
                </p>

                <KinetixAlert
                    v-if="preview.truncated"
                    color="warning"
                    :description="
                        t('kinetix.notification_routing_preview_truncated')
                    "
                    data-test="preview-truncated"
                />

                <div v-if="!unrouted" class="space-y-1.5">
                    <h5 class="text-xs font-medium text-muted-foreground">
                        {{
                            t('kinetix.notification_routing_preview_notified', {
                                count: preview.recipients.length,
                            })
                        }}
                    </h5>
                    <p
                        v-if="preview.recipients.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('kinetix.notification_routing_preview_nobody') }}
                    </p>
                    <ul
                        v-else
                        class="max-h-56 divide-y divide-border overflow-y-auto rounded-md border border-border bg-background"
                        data-test="preview-recipients"
                    >
                        <li
                            v-for="person in preview.recipients"
                            :key="person.id"
                            class="gap-2 px-3 py-2 text-sm flex items-center justify-between"
                        >
                            <span
                                class="min-w-0 truncate text-foreground"
                                :title="person.name"
                                >{{ person.name }}</span
                            >
                            <KinetixNotificationChannelIcons
                                :channels="person.channels"
                                :labels="channelLabels"
                            />
                        </li>
                    </ul>
                </div>

                <div v-if="skippedTotal > 0" class="space-y-1.5">
                    <h5 class="text-xs font-medium text-muted-foreground">
                        {{
                            t('kinetix.notification_routing_preview_skipped', {
                                count: skippedTotal,
                            })
                        }}
                    </h5>
                    <ul
                        class="max-h-56 divide-y divide-border overflow-y-auto rounded-md border border-border bg-background"
                        data-test="preview-skipped"
                    >
                        <li
                            v-for="person in preview.skipped"
                            :key="person.id"
                            class="gap-x-3 gap-y-0.5 px-3 py-2 text-sm flex flex-wrap items-baseline justify-between"
                        >
                            <span
                                class="min-w-0 truncate text-foreground"
                                :title="person.name"
                                >{{ person.name }}</span
                            >
                            <span class="text-xs text-muted-foreground">{{
                                reasonLabel(person.reason)
                            }}</span>
                        </li>
                        <li
                            v-if="skippedTotal > preview.skipped.length"
                            class="px-3 py-2 text-xs text-muted-foreground"
                            data-test="preview-skipped-more"
                        >
                            {{
                                t('kinetix.notification_routing_preview_more', {
                                    count:
                                        skippedTotal - preview.skipped.length,
                                })
                            }}
                        </li>
                    </ul>
                </div>
            </template>
        </div>
    </section>
</template>
