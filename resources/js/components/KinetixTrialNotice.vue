<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import KinetixAlert from './KinetixAlert.vue';

const { t } = useI18n();
</script>

<template>
    <KinetixAlert
        color="info"
        :title="t('kinetix.billing_trial_notice_title')"
        :description="t('kinetix.billing_trial_notice_description')"
    />
</template>
