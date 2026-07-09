<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import Alert from './primitives/Alert.vue';
import AlertDescription from './primitives/AlertDescription.vue';
import AlertTitle from './primitives/AlertTitle.vue';

const { t } = useI18n();
</script>

<template>
    <Alert class="flex items-start gap-2 [&>svg]:static [&>svg]:translate-y-0 [&>svg+div]:translate-y-0">
        <component :is="resolveIcon('info')" class="size-4 shrink-0 text-blue-500" />
        <div>
            <AlertTitle>{{ t('kinetix.billing_trial_notice_title') }}</AlertTitle>
            <AlertDescription>{{
                t('kinetix.billing_trial_notice_description')
            }}</AlertDescription>
        </div>
    </Alert>
</template>
