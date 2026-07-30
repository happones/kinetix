<script setup lang="ts">
import type { KinetixWidget } from '@/types/kinetix';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardDescription from './primitives/CardDescription.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';

defineProps<{
    widget: KinetixWidget;
}>();
</script>

<template>
    <Card
        class="kinetix-custom-card hover:shadow-md transition-all duration-300"
    >
        <CardHeader v-if="widget.title || widget.description">
            <CardTitle v-if="widget.title" class="text-base">{{
                widget.title
            }}</CardTitle>
            <CardDescription v-if="widget.description" class="text-xs">
                {{ widget.description }}
            </CardDescription>
        </CardHeader>

        <CardContent class="kinetix-custom-content">
            <slot />
        </CardContent>
    </Card>
</template>

<style scoped>
.kinetix-custom-card {
    width: 100%;
}
</style>
