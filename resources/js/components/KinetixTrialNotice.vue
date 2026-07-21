<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import Alert from './primitives/Alert.vue';
import AlertDescription from './primitives/AlertDescription.vue';
import AlertTitle from './primitives/AlertTitle.vue';

const { t } = useI18n();
</script>

<template>
    <Alert
        class="gap-2 [&>svg]:translate-y-0 [&>svg+div]:translate-y-0 flex items-start [&>svg]:static"
    >
        <component
            :is="resolveIcon('info')"
            class="size-4 text-blue-500 shrink-0"
        />
        <div>
            <AlertTitle>{{
                t('kinetix.billing_trial_notice_title')
            }}</AlertTitle>
            <AlertDescription>{{
                t('kinetix.billing_trial_notice_description')
            }}</AlertDescription>
        </div>
    </Alert>
</template>
