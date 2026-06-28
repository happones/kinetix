<script setup lang="ts">
import { computed } from 'vue';
import type { KinetixWidget } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardDescription from './primitives/CardDescription.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';
import WidgetHeaderActions from './widgets/WidgetHeaderActions.vue';

const props = defineProps<{
    widget: KinetixWidget;
}>();

const headers = computed<string[]>(() => {
    if (!props.widget.data || !Array.isArray(props.widget.data.headers)) {
        return [];
    }

    return props.widget.data.headers;
});

const rows = computed<any[]>(() => {
    if (!props.widget.data || !Array.isArray(props.widget.data.rows)) {
        return [];
    }

    return props.widget.data.rows;
});

const getRowValues = (row: any) => {
    if (Array.isArray(row)) {
        return row;
    }

    if (typeof row === 'object' && row !== null) {
        return Object.values(row);
    }

    return [row];
};
</script>

<template>
    <Card
        class="kinetix-table-card hover:shadow-md overflow-hidden transition-all duration-300"
    >
        <CardHeader
            v-if="
                widget.title ||
                widget.description ||
                widget.headerActions?.length
            "
        >
            <div class="gap-3 flex items-start justify-between">
                <div class="min-w-0">
                    <CardTitle v-if="widget.title" class="text-base">{{
                        widget.title
                    }}</CardTitle>
                    <CardDescription v-if="widget.description" class="text-xs">
                        {{ widget.description }}
                    </CardDescription>
                </div>
                <WidgetHeaderActions :actions="widget.headerActions" />
            </div>
        </CardHeader>

        <CardContent class="-mx-6 -mb-6 overflow-x-auto">
            <div class="inline-block min-w-full align-middle">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/40">
                        <tr>
                            <th
                                v-for="(header, i) in headers"
                                :key="i"
                                scope="col"
                                class="px-6 py-3 text-xs font-semibold tracking-wider text-left text-muted-foreground uppercase"
                            >
                                {{ header }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-transparent">
                        <tr
                            v-for="(row, rowIndex) in rows"
                            :key="rowIndex"
                            class="transition-colors hover:bg-accent/50"
                        >
                            <td
                                v-for="(val, colIndex) in getRowValues(row)"
                                :key="colIndex"
                                class="px-6 py-4 text-sm whitespace-nowrap text-foreground"
                            >
                                {{ val }}
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td
                                :colspan="headers.length || 1"
                                class="px-6 py-10 text-sm text-center text-muted-foreground"
                            >
                                No records found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>

<style scoped>
.kinetix-table-card {
    width: 100%;
}
</style>
