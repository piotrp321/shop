<script setup lang="ts">
import PageHeader from "@/components/PageHeader.vue";
import { Skeleton } from "@/components/ui/skeleton";
import FreshnessBar from "@/dashboard/FreshnessBar.vue";
import SystemStatus from "@/dashboard/SystemStatus.vue";
import { useDashboard } from "@/dashboard/useDashboard";

const { data, error, loading, updatedAt, refresh } = useDashboard();
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      title="Stan systemu"
      :crumbs="[{ label: 'Stan systemu' }]"
    />
    <FreshnessBar
      :updated-at="updatedAt"
      :loading="loading"
      :error="error"
      @refresh="refresh()"
    />

    <template v-if="data">
      <SystemStatus
        v-if="data.system"
        :system="data.system"
      />
      <p
        v-else
        data-testid="system-unavailable"
        class="text-sm text-muted-foreground"
      >
        Brak danych o stanie systemu. Ten ekran jest dla administratorów.
      </p>
    </template>
    <div
      v-else-if="loading"
      data-testid="dashboard-loading"
      class="flex flex-col gap-4"
    >
      <Skeleton class="h-32" />
      <Skeleton class="h-48" />
    </div>
  </div>
</template>
