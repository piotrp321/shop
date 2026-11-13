<script setup lang="ts">
import Button from "primevue/button";
import Message from "primevue/message";
import Skeleton from "primevue/skeleton";
import { Alert, AlertDescription } from "@/components/ui/alert";
import CatalogStats from "@/dashboard/CatalogStats.vue";
import SystemStatus from "@/dashboard/SystemStatus.vue";
import { formatTime, useDashboard } from "@/dashboard/useDashboard";
import { useAccessDenied } from "@/layout/useAccessDenied";

const accessDenied = useAccessDenied();
const { data, error, loading, updatedAt, refresh } = useDashboard();
</script>

<template>
  <div class="dashboard">
    <Alert
      v-if="accessDenied"
      data-testid="access-denied"
      variant="destructive"
    >
      <AlertDescription>Nie masz dostępu do tej strony.</AlertDescription>
    </Alert>
    <div class="heading">
      <h1>Dashboard</h1>
      <div class="actions">
        <span
          v-if="updatedAt"
          data-testid="updated-at"
          class="updated-at"
        >
          Dane z {{ formatTime(updatedAt) }}
        </span>
        <Button
          data-testid="refresh"
          label="Odśwież"
          icon="pi pi-refresh"
          severity="secondary"
          :loading="loading"
          @click="refresh()"
        />
      </div>
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

.actions {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.updated-at {
  color: var(--p-text-muted-color);
}

.skeletons {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
</style>
