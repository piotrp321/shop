<script setup lang="ts">
import { nextTick, ref } from "vue";
import { RouterLink } from "vue-router";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { focusAfterAction } from "@/lib/focus";
import { type OrderStatus, statusLabel } from "@/orders/format";
import { formatDateTime, formatMoney } from "@/prices/format";
import type { RecentOrder } from "./useRecentOrders";

const props = defineProps<{
  items: RecentOrder[];
  loaded: boolean;
  error: string | null;
  forbidden: boolean;
  retry: () => Promise<void>;
}>();

const BADGES: Record<OrderStatus, "warning" | "info" | "success" | "neutral" | "danger"> = {
  new: "warning",
  paid: "info",
  shipped: "success",
  delivered: "info",
  cancelled: "danger",
  returned: "neutral",
};

const heading = ref<HTMLElement | null>(null);
const retryButton = ref<InstanceType<typeof Button> | null>(null);

// A successful retry removes the message with its button: the focus goes back to the button while it is still there
// (the retry failed), otherwise to the heading of the card, never to the body.
async function retryAndRefocus() {
  const trigger = (retryButton.value?.$el as HTMLElement | undefined) ?? null;
  await props.retry();
  await nextTick();
  focusAfterAction(trigger, heading.value);
}
</script>

<template>
  <Card
    data-testid="recent-orders"
    class="gap-4 p-5"
  >
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
      <h2
        ref="heading"
        tabindex="-1"
        class="text-base font-bold text-heading focus:outline-none"
      >
        Ostatnie zamówienia
      </h2>
      <RouterLink
        :to="{ name: 'orders' }"
        data-testid="all-orders"
        class="rounded-sm text-sm font-semibold text-interface underline-offset-4 hover:underline"
      >
        Wszystkie zamówienia →
      </RouterLink>
    </div>

    <div
      v-if="error"
      data-testid="orders-error"
      role="alert"
      class="flex flex-wrap items-center gap-3 rounded-lg border border-destructive px-4 py-3 text-sm text-destructive"
    >
      <span>{{ error }}</span>
      <Button
        v-if="!forbidden"
        ref="retryButton"
        type="button"
        variant="outline"
        size="sm"
        data-testid="orders-retry"
        @click="retryAndRefocus"
      >
        Spróbuj ponownie
      </Button>
    </div>

    <template v-if="loaded">
      <p
        v-if="items.length === 0"
        class="text-sm text-muted-foreground"
      >
        Nie ma jeszcze zamówień.
      </p>
      <div
        v-else
        role="region"
        aria-label="Ostatnie zamówienia"
        tabindex="0"
        class="overflow-x-auto"
      >
        <table class="w-full caption-bottom text-sm">
          <TableHeader>
            <TableRow class="hover:bg-transparent">
              <TableHead class="px-3 text-xs font-bold text-muted-foreground uppercase">
                Numer
              </TableHead>
              <TableHead class="px-3 text-xs font-bold text-muted-foreground uppercase">
                Klient
              </TableHead>
              <TableHead class="px-3 text-xs font-bold text-muted-foreground uppercase">
                Data
              </TableHead>
              <TableHead class="px-3 text-right text-xs font-bold text-muted-foreground uppercase">
                Pozycje
              </TableHead>
              <TableHead class="px-3 text-right text-xs font-bold text-muted-foreground uppercase">
                Kwota
              </TableHead>
              <TableHead class="px-3 text-xs font-bold text-muted-foreground uppercase">
                Status
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="order in items"
              :key="order.orderId"
              data-testid="recent-order"
            >
              <TableCell class="px-3 font-bold whitespace-nowrap">
                <RouterLink
                  :to="{ name: 'order-detail', params: { orderId: order.orderId } }"
                  class="rounded-sm text-interface underline-offset-4 hover:underline"
                >
                  {{ order.number }}
                </RouterLink>
              </TableCell>
              <TableCell
                data-testid="order-customer"
                class="px-3"
              >
                {{ order.fullName }}
              </TableCell>
              <TableCell
                data-testid="order-date"
                class="px-3 whitespace-nowrap"
              >
                {{ formatDateTime(order.placedAt) }}
              </TableCell>
              <TableCell
                data-testid="order-items"
                class="px-3 text-right tabular-nums"
              >
                {{ order.itemCount }}
              </TableCell>
              <TableCell
                data-testid="order-total"
                class="px-3 text-right whitespace-nowrap tabular-nums"
              >
                {{ formatMoney(order.totalGross, order.currency) }}
              </TableCell>
              <TableCell class="px-3">
                <Badge
                  data-testid="order-status"
                  :variant="BADGES[order.status] ?? 'neutral'"
                >
                  {{ statusLabel(order.status).label }}
                </Badge>
              </TableCell>
            </TableRow>
          </TableBody>
        </table>
      </div>
    </template>
    <div
      v-else-if="!error"
      data-testid="orders-loading"
      class="flex flex-col gap-2"
    >
      <Skeleton class="h-8" />
      <Skeleton class="h-8" />
      <Skeleton class="h-8" />
    </div>
  </Card>
</template>
