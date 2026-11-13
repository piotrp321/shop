<script setup lang="ts">
import { ArrowUpRight } from "lucide-vue-next";
import type { Component } from "vue";
import type { RouteLocationRaw } from "vue-router";
import { RouterLink } from "vue-router";
import { Card } from "@/components/ui/card";

export type KpiTone = "blue" | "violet" | "green" | "teal" | "amber";

/**
 * A tile of the dashboard (docs/features/admin-design/spec.md, „Wzorzec wyglądu”): the title, the icon in a pastel
 * circle of the colour pair `kpi-<tone>`, the value (already formatted by the screen) and optionally a footer with a
 * dot of the same colour. With `to` the tile is a link: the title is the link and stretches over the tile.
 */
defineProps<{
  title: string;
  icon: Component;
  tone: KpiTone;
  value: string | number;
  footer?: { label: string; value?: string | number; testId?: string };
  to?: RouteLocationRaw;
}>();

// Whole class names, so that Tailwind finds them.
const TONES: Record<KpiTone, { circle: string; dot: string }> = {
  blue: { circle: "bg-kpi-blue-soft text-kpi-blue", dot: "bg-kpi-blue" },
  violet: { circle: "bg-kpi-violet-soft text-kpi-violet", dot: "bg-kpi-violet" },
  green: { circle: "bg-kpi-green-soft text-kpi-green", dot: "bg-kpi-green" },
  teal: { circle: "bg-kpi-teal-soft text-kpi-teal", dot: "bg-kpi-teal" },
  amber: { circle: "bg-kpi-amber-soft text-kpi-amber", dot: "bg-kpi-amber" },
};
</script>

<template>
  <Card
    data-testid="kpi-tile"
    :class="['relative gap-4 p-5', to ? 'transition-shadow hover:shadow-md' : '']"
  >
    <div class="flex items-start justify-between gap-3">
      <h2 class="text-base font-bold text-heading">
        <RouterLink
          v-if="to"
          :to="to"
          data-testid="kpi-title"
          class="rounded-sm after:absolute after:inset-0 after:rounded-lg"
        >
          {{ title }}
        </RouterLink>
        <span
          v-else
          data-testid="kpi-title"
        >{{ title }}</span>
      </h2>
      <ArrowUpRight
        v-if="to"
        class="size-5 shrink-0 text-muted-foreground"
        aria-hidden="true"
      />
    </div>
    <div class="flex items-center gap-4">
      <span
        data-testid="kpi-icon"
        :class="['flex size-11.5 shrink-0 items-center justify-center rounded-full', TONES[tone].circle]"
      >
        <component
          :is="icon"
          class="size-6"
          aria-hidden="true"
        />
      </span>
      <span
        data-testid="kpi-value"
        class="text-2xl font-bold text-heading tabular-nums"
      >
        <slot name="value">{{ value }}</slot>
      </span>
    </div>
    <p
      v-if="footer"
      :data-testid="footer.testId ?? 'kpi-footer'"
      class="flex items-center gap-2 border-t border-separator pt-3 text-sm text-muted-foreground"
    >
      <span
        data-testid="kpi-dot"
        :class="['size-2.5 shrink-0 rounded-full', TONES[tone].dot]"
        aria-hidden="true"
      />
      <span>{{ footer.label }}</span>
      <span
        v-if="footer.value !== undefined"
        class="ml-auto font-bold text-foreground tabular-nums"
      >{{ footer.value }}</span>
    </p>
  </Card>
</template>
