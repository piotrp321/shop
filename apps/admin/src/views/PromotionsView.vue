<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Message from "primevue/message";
import SelectButton from "primevue/selectbutton";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import { computed, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import { formatDateTime } from "@/prices/format";
import { STATUS_FILTER_OPTIONS, statusLabel, statusSeverity } from "@/promotions/format";
import { canManagePromotions } from "@/promotions/permissions";
import { type PromotionStatus, usePromotionList } from "@/promotions/usePromotionList";
import { useAuthStore } from "@/stores/auth";

const { items, loading, loaded, error, forbidden, emptyText, hasPrevious, hasNext, pageNumber, selectStatus, goNext, goPrevious, retry } =
  usePromotionList();

const statusFilter = ref<"all" | PromotionStatus>("all");
watch(statusFilter, (value) => selectStatus(value === "all" ? null : value));

// Only hides the action: the API checks the permission. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canAdd = computed(() => canManagePromotions(auth.staff));
</script>

<template>
  <div class="promotions">
    <div class="header">
      <h1>Promocje</h1>
      <RouterLink
        v-if="canAdd"
        v-slot="{ href, navigate }"
        :to="{ name: 'promotion-new' }"
        custom
      >
        <Button
          as="a"
          data-testid="add-promotion"
          :href="href"
          label="Dodaj promocję"
          icon="pi pi-plus"
          @click="navigate"
        />
      </RouterLink>
    </div>

    <div class="filters">
      <SelectButton
        v-model="statusFilter"
        data-testid="status-filter"
        :options="STATUS_FILTER_OPTIONS"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        aria-label="Filtr statusu"
      />
    </div>

    <Message
      v-if="error"
      data-testid="promotions-error"
      severity="error"
    >
      {{ error }}
      <Button
        v-if="!forbidden"
        data-testid="retry"
        label="Spróbuj ponownie"
        icon="pi pi-refresh"
        severity="secondary"
        size="small"
        :loading="loading"
        @click="retry()"
      />
    </Message>

    <DataTable
      v-if="loaded"
      :value="items"
      :loading="loading"
      data-key="id"
      :pt="{ bodyRow: { 'data-testid': 'promotion-row' } }"
    >
      <template #empty>
        <span data-testid="empty">{{ emptyText }}</span>
      </template>
      <Column header="Nazwa">
        <template #body="{ data }">
          <RouterLink
            data-testid="promotion-link"
            :to="{ name: 'promotion', params: { id: data.id } }"
          >
            {{ data.name }}
          </RouterLink>
        </template>
      </Column>
      <Column
        header="Obniżka"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="promotion-percent">{{ data.percentOff }}%</span>
        </template>
      </Column>
      <Column header="Początek">
        <template #body="{ data }">
          <span data-testid="promotion-starts">{{ formatDateTime(data.startsAt) }}</span>
        </template>
      </Column>
      <Column header="Koniec">
        <template #body="{ data }">
          <span data-testid="promotion-ends">{{ formatDateTime(data.endsAt) }}</span>
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag
            data-testid="promotion-status"
            :severity="statusSeverity(data.status)"
            :value="statusLabel(data.status)"
          />
        </template>
      </Column>
      <Column
        header="Warianty"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="variant-count">{{ data.variantCount }}</span>
        </template>
      </Column>
    </DataTable>
    <Skeleton
      v-else-if="loading"
      height="16rem"
    />

    <div
      v-if="loaded"
      class="pager"
    >
      <Button
        data-testid="previous-page"
        label="Poprzednia"
        icon="pi pi-chevron-left"
        severity="secondary"
        :disabled="!hasPrevious || loading"
        @click="goPrevious()"
      />
      <span
        data-testid="page-number"
        class="page-number"
      >Strona {{ pageNumber }}</span>
      <Button
        data-testid="next-page"
        label="Następna"
        icon="pi pi-chevron-right"
        icon-pos="right"
        severity="secondary"
        :disabled="!hasNext || loading"
        @click="goNext()"
      />
    </div>
  </div>
</template>

<style scoped>
.promotions {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 1rem;
}

.amount {
  text-align: right;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 1rem;
}

.page-number {
  color: var(--p-text-muted-color);
}
</style>
