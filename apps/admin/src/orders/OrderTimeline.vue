<script setup lang="ts">
import type { components } from "@/api/schema";
import { Badge } from "@/components/ui/badge";
import { formatDateTime } from "@/prices/format";
import { actorLabel, statusLabel } from "./format";

type StatusChange = components["schemas"]["BackofficeOrderStatusChange"];

/**
 * The history of the statuses of an order as a timeline (docs/features/admin-screens/spec.md, „Oś czasu”): the entries
 * in the order of the API, oldest first, with nothing added and nothing sorted. An entry has the time (`<time>`), the
 * change („Opłacone → Wysłane”; the first entry, from `null`, is only the status it started in), who made it and the note.
 */
defineProps<{ history: StatusChange[] }>();
</script>

<template>
  <ol class="flex flex-col gap-5 border-l border-separator pl-5">
    <li
      v-for="(entry, index) in history"
      :key="index"
      data-testid="history-row"
      class="relative flex flex-col gap-1"
    >
      <span
        aria-hidden="true"
        class="absolute top-1.5 -left-[1.6875rem] size-2.5 rounded-full bg-primary"
      />
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span
          data-testid="history-change"
          class="inline-flex flex-wrap items-center gap-1.5"
        >
          <Badge
            v-if="entry.from"
            :variant="statusLabel(entry.from).variant"
          >{{ statusLabel(entry.from).label }}</Badge>
          <template v-if="entry.from">{{ " → " }}</template>
          <Badge :variant="statusLabel(entry.to).variant">{{ statusLabel(entry.to).label }}</Badge>
        </span>
        <time
          data-testid="history-at"
          :datetime="entry.occurredAt"
          class="text-xs text-muted-foreground"
        >{{ formatDateTime(entry.occurredAt) }}</time>
      </div>
      <p class="text-sm">
        <span class="text-muted-foreground">Kto: </span><span data-testid="history-actor">{{ actorLabel(entry) }}</span>
      </p>
      <p
        v-if="entry.note"
        data-testid="history-note"
        class="text-sm break-words whitespace-pre-line"
      >
        {{ entry.note }}
      </p>
    </li>
  </ol>
</template>
