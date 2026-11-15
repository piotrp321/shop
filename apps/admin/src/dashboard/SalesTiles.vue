<script setup lang="ts">
import { computed } from "vue";
import { CalendarDays, CalendarRange, TrendingUp } from "lucide-vue-next";
import KpiTile, { type KpiTone, type KpiTrend } from "./KpiTile.vue";
import type { Dashboard } from "./useDashboard";
import { formatMoney } from "@/prices/format";

type Sales = NonNullable<Dashboard["sales"]>;
type Period = Sales["today"];

const props = defineProps<{ sales: Sales }>();

// Poland is the only market: the hour the data reach is the hour of the Polish clock, whatever the browser says.
const hourFormatter = new Intl.DateTimeFormat("pl-PL", { timeZone: "Europe/Warsaw", hour: "2-digit", minute: "2-digit", hourCycle: "h23" });
const changeFormatter = new Intl.NumberFormat("pl-PL", { style: "percent", maximumFractionDigits: 1, signDisplay: "exceptZero" });
const countFormatter = new Intl.NumberFormat("pl-PL");

const until = computed(() => `Dane do ${hourFormatter.format(new Date(props.sales.dataUntil))}`);

/** The change of the revenue from the API, in basis points, as „+12,5% vs wczoraj”; the panel computes nothing. */
function trend(period: Period, against: string): KpiTrend | undefined {
  if (period.revenue === null) {
    return undefined;
  }
  const change = period.changeBasisPoints;
  if (change === null) {
    return { label: "brak danych do porównania", tone: "neutral" };
  }
  return { label: `${changeFormatter.format(change / 10000)} ${against}`, tone: change > 0 ? "success" : change < 0 ? "danger" : "neutral" };
}

function footer(period: Period) {
  if (period.orderCount === null) {
    return undefined;
  }
  const average = period.averageOrderValue === null ? "—" : formatMoney(period.averageOrderValue, props.sales.currency);
  return { label: `Zamówienia: ${countFormatter.format(period.orderCount)}`, value: `śr. ${average}` };
}

const tiles = computed(() => {
  const today = props.sales.today;
  return [
    {
      title: "Sprzedaż dziś",
      icon: TrendingUp,
      tone: "green" as KpiTone,
      period: today,
      against: "vs wczoraj",
      description: today.revenue === null ? "Pierwsze dane po 1:00" : "Od północy do pełnej godziny, wg godzin zegara.",
    },
    { title: "Sprzedaż 7 dni", icon: CalendarDays, tone: "teal" as KpiTone, period: props.sales.last7Days, against: "vs poprzednie 7 dni", description: undefined },
    { title: "Sprzedaż 30 dni", icon: CalendarRange, tone: "blue" as KpiTone, period: props.sales.last30Days, against: "vs poprzednie 30 dni", description: undefined },
  ].map((tile) => ({
    ...tile,
    value: tile.period.revenue === null ? "—" : formatMoney(tile.period.revenue, props.sales.currency),
    footer: footer(tile.period),
    trend: trend(tile.period, tile.against),
  }));
});
</script>

<template>
  <section
    aria-label="Sprzedaż"
    class="flex flex-col gap-3"
  >
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
      <KpiTile
        v-for="tile in tiles"
        :key="tile.title"
        :title="tile.title"
        :icon="tile.icon"
        :tone="tile.tone"
        :value="tile.value"
        :footer="tile.footer"
        :trend="tile.trend"
        :description="tile.description"
      />
    </div>
    <p
      data-testid="sales-until"
      class="text-sm text-muted-foreground"
    >
      {{ until }}
    </p>
  </section>
</template>
