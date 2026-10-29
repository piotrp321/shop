<script setup lang="ts">
import Button from "primevue/button";
import Message from "primevue/message";
import Skeleton from "primevue/skeleton";
import CatalogStats from "@/dashboard/CatalogStats.vue";
import SystemStatus from "@/dashboard/SystemStatus.vue";
import { useDashboard } from "@/dashboard/useDashboard";

const { data, error, loading, refresh } = useDashboard();
</script>

<template>
  <div class="dashboard">
    <div class="heading">
      <h1>Dashboard</h1>
      <Button
        data-testid="refresh"
        label="Odśwież"
        icon="pi pi-refresh"
        severity="secondary"
        :loading="loading"
        @click="refresh()"
      />
    </div>

    <Message
      v-if="error"
      data-testid="dashboard-error"
      severity="error"
    >
      {{ error }}
    </Message>

    <template v-if="data">
      <CatalogStats :catalog="data.catalog" />
      <SystemStatus
        v-if="data.system"
        :system="data.system"
      />
    </template>
    <div
      v-else-if="loading"
      class="skeletons"
    >
      <Skeleton height="8rem" />
      <Skeleton height="12rem" />
    </div>
  </div>
</template>

<style scoped>
.dashboard {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.skeletons {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
</style>
