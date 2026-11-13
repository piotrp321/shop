<script setup lang="ts">
import { RefreshCw } from "lucide-vue-next";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { formatFetchTime } from "./useDashboard";

/**
 * The freshness of the data of a dashboard screen (docs/features/dashboard-data-freshness/spec.md): the time of the
 * last successful fetch, „Odśwież”, and the error that names the time of the data still shown.
 */
defineProps<{ updatedAt: Date | null; loading: boolean; error: string | null }>();
defineEmits<{ refresh: [] }>();
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2">
      <div
        aria-live="polite"
        aria-atomic="true"
      >
        <span
          v-if="updatedAt"
          data-testid="updated-at"
          class="text-sm text-muted-foreground"
        >Dane z {{ formatFetchTime(updatedAt) }}</span>
      </div>
      <Button
        type="button"
        variant="outline"
        data-testid="refresh"
        :aria-busy="loading"
        @click="$emit('refresh')"
      >
        <RefreshCw
          :class="{ 'animate-spin': loading }"
          aria-hidden="true"
        />
        Odśwież
      </Button>
    </div>
    <Alert
      v-if="error"
      variant="destructive"
      data-testid="dashboard-error"
    >
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>
  </div>
</template>
