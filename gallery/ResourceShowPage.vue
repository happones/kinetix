<script setup lang="ts">
/**
 * Mirror of the `make-resource` Show.vue scaffold (gallery import paths).
 * Keep in sync with the template in MakeResourceCommand.php.
 */
import KinetixInfolist from '@/components/KinetixInfolist.vue';
import KinetixPageHeader from '@/components/KinetixPageHeader.vue';
import type { KinetixAction, KinetixInfolistData } from '@/types/kinetix';

defineProps<{
    infolist: KinetixInfolistData;
    actions: KinetixAction[];
}>();
</script>

<template>
    <div class="max-w-5xl space-y-6 p-4 sm:p-6 lg:p-8 mx-auto w-full">
        <KinetixPageHeader heading="Post details" :actions="actions" />
        <KinetixInfolist :infolist="infolist" />
    </div>
</template>
