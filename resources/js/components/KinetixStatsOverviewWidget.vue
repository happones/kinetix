<script setup lang="ts">
import {
  TrendingUp,
  TrendingDown,
  ArrowUpRight,
  ArrowDownRight,
  ArrowUp,
  ArrowDown,
  Percent,
  DollarSign,
  Users,
  Activity,
  ShoppingCart,
  AlertCircle,
  Info,
  CheckCircle,
} from "@lucide/vue";
import { computed } from "vue";
import type { KinetixWidget, KinetixStat } from "@/types";

const props = defineProps<{
  widget: KinetixWidget;
}>();

const stats = computed<KinetixStat[]>(() => {
  if (!props.widget.data || !Array.isArray(props.widget.data.stats)) {
    return [];
  }

  return props.widget.data.stats;
});

const iconMap: Record<string, any> = {
  "heroicon-m-arrow-trending-up": TrendingUp,
  "heroicon-m-arrow-trending-down": TrendingDown,
  "arrow-trending-up": TrendingUp,
  "arrow-trending-down": TrendingDown,
  "trending-up": TrendingUp,
  "trending-down": TrendingDown,
  "arrow-up-right": ArrowUpRight,
  "arrow-down-right": ArrowDownRight,
  "arrow-up": ArrowUp,
  "arrow-down": ArrowDown,
  percent: Percent,
  "dollar-sign": DollarSign,
  users: Users,
  activity: Activity,
  "shopping-cart": ShoppingCart,
  "alert-circle": AlertCircle,
  info: Info,
  "check-circle": CheckCircle,
};

const getStatIcon = (iconName?: string) => {
  if (!iconName) {
    return null;
  }

  const resolved = iconMap[iconName.toLowerCase()];

  if (resolved) {
    return resolved;
  }

  return null;
};

const getDescriptionColorClass = (color?: string) => {
  if (color === "success") {
    return "text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/30";
  }

  if (color === "danger") {
    return "text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/30";
  }

  if (color === "warning") {
    return "text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/30";
  }

  if (color === "info") {
    return "text-sky-600 dark:text-sky-400 bg-sky-50 dark:bg-sky-950/30";
  }

  return "text-muted-foreground bg-muted/40";
};

const getSparklineColor = (color?: string) => {
  if (color === "success") {
    return {
      stroke: "#10b981",
      fill: "url(#sparkline-grad-success)",
    };
  }

  if (color === "danger") {
    return {
      stroke: "#f43f5e",
      fill: "url(#sparkline-grad-danger)",
    };
  }

  if (color === "warning") {
    return {
      stroke: "#f59e0b",
      fill: "url(#sparkline-grad-warning)",
    };
  }

  if (color === "info") {
    return {
      stroke: "#0ea5e9",
      fill: "url(#sparkline-grad-info)",
    };
  }

  return {
    stroke: "#737373",
    fill: "url(#sparkline-grad-gray)",
  };
};

const getSparklinePath = (chart?: number[], width = 120, height = 40) => {
  if (!chart || chart.length < 2) {
    return { line: "", area: "" };
  }

  const min = Math.min(...chart);
  const max = Math.max(...chart);
  const range = max - min === 0 ? 1 : max - min;
  const padding = 4;
  const usableHeight = height - padding * 2;

  const points = chart.map((val, index) => {
    const x = (index / (chart.length - 1)) * width;
    const y = height - padding - ((val - min) / range) * usableHeight;

    return { x, y };
  });

  const linePath = points
    .map((p, i) => {
      if (i === 0) {
        return `M ${p.x.toFixed(1)} ${p.y.toFixed(1)}`;
      }

      return `L ${p.x.toFixed(1)} ${p.y.toFixed(1)}`;
    })
    .join(" ");

  const last = points[points.length - 1];
  const first = points[0];
  const areaPath = `${linePath} L ${last.x.toFixed(1)} ${height} L ${first.x.toFixed(1)} ${height} Z`;

  return { line: linePath, area: areaPath };
};
</script>

<template>
  <div class="kinetix-stats-wrapper">
    <div v-if="widget.title || widget.description" class="mb-4">
      <h3
        v-if="widget.title"
        class="text-base font-semibold text-foreground leading-6"
      >
        {{ widget.title }}
      </h3>
      <p
        v-if="widget.description"
        class="text-xs text-muted-foreground mt-1"
      >
        {{ widget.description }}
      </p>
    </div>

    <div class="kinetix-stats-grid" :style="{ '--stats-cols': stats.length }">
      <!-- Gradients Definitions -->
      <svg style="width: 0; height: 0; position: absolute" aria-hidden="true">
        <defs>
          <linearGradient
            id="sparkline-grad-success"
            x1="0"
            y1="0"
            x2="0"
            y2="1"
          >
            <stop offset="0%" stop-color="#10b981" stop-opacity="0.2" />
            <stop offset="100%" stop-color="#10b981" stop-opacity="0" />
          </linearGradient>
          <linearGradient
            id="sparkline-grad-danger"
            x1="0"
            y1="0"
            x2="0"
            y2="1"
          >
            <stop offset="0%" stop-color="#f43f5e" stop-opacity="0.2" />
            <stop offset="100%" stop-color="#f43f5e" stop-opacity="0" />
          </linearGradient>
          <linearGradient
            id="sparkline-grad-warning"
            x1="0"
            y1="0"
            x2="0"
            y2="1"
          >
            <stop offset="0%" stop-color="#f59e0b" stop-opacity="0.2" />
            <stop offset="100%" stop-color="#f59e0b" stop-opacity="0" />
          </linearGradient>
          <linearGradient id="sparkline-grad-info" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#0ea5e9" stop-opacity="0.2" />
            <stop offset="100%" stop-color="#0ea5e9" stop-opacity="0" />
          </linearGradient>
          <linearGradient id="sparkline-grad-gray" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#737373" stop-opacity="0.15" />
            <stop offset="100%" stop-color="#737373" stop-opacity="0" />
          </linearGradient>
        </defs>
      </svg>

      <div
        v-for="(stat, index) in stats"
        :key="index"
        class="kinetix-stat-card border border-border bg-card backdrop-blur-sm rounded-xl p-6 relative overflow-hidden transition-all duration-300 hover:shadow-md hover:-translate-y-0.5 group"
      >
        <div class="flex justify-between items-start gap-4">
          <div class="flex-1 min-w-0">
            <span
              class="text-sm font-medium text-muted-foreground block truncate"
            >
              {{ stat.label }}
            </span>
            <span
              class="text-3xl font-bold text-foreground mt-2 block tracking-tight"
            >
              {{ stat.value }}
            </span>
          </div>

          <!-- Sparkline Chart -->
          <div
            v-if="stat.chart && stat.chart.length >= 2"
            class="w-[120px] h-[40px] shrink-0 mt-1"
          >
            <svg class="w-full h-full overflow-visible" viewBox="0 0 120 40">
              <path
                :d="getSparklinePath(stat.chart, 120, 40).area"
                :fill="getSparklineColor(stat.descriptionColor).fill"
                stroke="none"
              />
              <path
                :d="getSparklinePath(stat.chart, 120, 40).line"
                fill="none"
                :stroke="getSparklineColor(stat.descriptionColor).stroke"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </div>
        </div>

        <!-- Description / Trend Badge -->
        <div
          v-if="stat.description"
          class="mt-4 flex items-center gap-1.5 flex-wrap"
        >
          <span
            class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full shrink-0"
            :class="getDescriptionColorClass(stat.descriptionColor)"
          >
            <component
              :is="getStatIcon(stat.descriptionIcon)"
              v-if="stat.descriptionIcon && getStatIcon(stat.descriptionIcon)"
              class="w-3.5 h-3.5"
            />
            {{ stat.description }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.kinetix-stats-grid {
  display: grid;
  gap: 1.5rem;
  grid-template-columns: repeat(1, minmax(0, 1fr));
}
@media (min-width: 640px) {
  .kinetix-stats-grid {
    grid-template-columns: repeat(min(var(--stats-cols, 3), 2), minmax(0, 1fr));
  }
}
@media (min-width: 1024px) {
  .kinetix-stats-grid {
    grid-template-columns: repeat(var(--stats-cols, 3), minmax(0, 1fr));
  }
}
</style>
