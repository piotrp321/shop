<script setup lang="ts">
import { computed } from "vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import CatalogTiles from "@/dashboard/CatalogTiles.vue";
import FreshnessBar from "@/dashboard/FreshnessBar.vue";
import RecentOrders from "@/dashboard/RecentOrders.vue";
import TodoCard from "@/dashboard/TodoCard.vue";
import { todoFor } from "@/dashboard/todo";
import { useDashboard } from "@/dashboard/useDashboard";
import { useRecentOrders } from "@/dashboard/useRecentOrders";
import { useAccessDenied } from "@/layout/useAccessDenied";
import { canViewOrders } from "@/orders/permissions";
import { useAuthStore } from "@/stores/auth";

const accessDenied = useAccessDenied();

// The roles come from /me, which the layout loads; the card and the rows only hide what the API would refuse anyway.
const auth = useAuthStore();
const rows = computed(() => todoFor(auth.staff));
const ordersAllowed = computed(() => canViewOrders(auth.staff));

const orders = useRecentOrders(ordersAllowed);
const { data, error, loading, updatedAt, refresh } = useDashboard(30000, () => void orders.reload());
</script>

<template>
  <div class="flex flex-col gap-6">
    <Alert
      v-if="accessDenied"
      data-testid="access-denied"
      variant="destructive"
    >
      <AlertDescription>Nie masz dostępu do tej strony.</AlertDescription>
    </Alert>

    <h1 class="text-xl font-bold text-heading">
      Pulpit
    </h1>
    <FreshnessBar
      :updated-at="updatedAt"
      :loading="loading"
      :error="error"
      @refresh="refresh()"
    />

    <CatalogTiles
      v-if="data"
      :catalog="data.catalog"
    />
    <div
      v-else-if="loading"
      data-testid="dashboard-loading"
      class="grid grid-cols-1 gap-6 xl:grid-cols-3"
    >
      <Skeleton class="h-40" />
      <Skeleton class="h-40" />
      <Skeleton class="h-40" />
    </div>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
      <TodoCard
        v-if="rows.length > 0"
        :items="rows"
      />
      <RecentOrders
        v-if="ordersAllowed"
        :class="rows.length > 0 ? 'xl:col-span-2' : 'xl:col-span-3'"
        :items="orders.items.value"
        :loaded="orders.loaded.value"
        :error="orders.error.value"
        :forbidden="orders.forbidden.value"
        :retry="orders.reload"
      />
    </div>
  </div>
</template>
