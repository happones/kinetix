<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <div class="rounded-xl shadow-sm border bg-card text-card-foreground">
        <div class="space-y-2 p-6 flex flex-col">
            <div class="gap-2 flex items-center">
                <ShieldCheck class="h-4 w-4 text-emerald-500" />
                <h3 class="font-semibold tracking-tight leading-none">
                    {{ t('kinetix.billing_secure_payments') }}
                </h3>
            </div>
            <p class="text-sm text-muted-foreground">
                {{ t('kinetix.billing_secure_payments_desc') }}
            </p>
        </div>
    </div>
</template>
