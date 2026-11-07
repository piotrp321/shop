<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Select from "primevue/select";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import ToggleSwitch from "primevue/toggleswitch";
import { RouterLink } from "vue-router";
import { STATUS_OPTIONS, statusLabel } from "@/orders/format";
import { MAX_SEARCH_LENGTH, useOrderList } from "@/orders/useOrderList";
import { formatDateTime, formatMoney } from "@/prices/format";

const {
  status,
  refundPending,
  search,
  items,
  loading,
  loaded,
  error,
  forbidden,
  emptyText,
  hasPrevious,
  hasNext,
  pageNumber,
  selectStatus,
  setRefundPending,
  submitSearch,
  goNext,
  goPrevious,
  retry,
} = useOrderList();
</script>

<template>
  <div class="orders">
    <h1>Zamówienia</h1>

    <div class="filters">
      <div class="field">
        <InputText
          v-model="search"
          data-testid="search"
          type="search"
          :maxlength="MAX_SEARCH_LENGTH"
          placeholder="Numer zamówienia lub e-mail"
          aria-label="Szukaj po numerze zamówienia lub e-mailu"
          @keydown.enter.prevent="submitSearch()"
        />
        <small class="hint">Dokładny numer (np. R-100001) albo dokładny e-mail, zatwierdź Enterem.</small>
      </div>
      <Select
        :model-value="status"
        data-testid="status-filter"
        class="status-filter"
        :options="STATUS_OPTIONS"
        option-label="label"
        option-value="value"
        placeholder="Wszystkie statusy"
        show-clear
        aria-label="Filtr statusu"
        @update:model-value="selectStatus($event ?? null)"
      />
      <div class="switch">
        <ToggleSwitch
          :model-value="refundPending"
          input-id="refund-pending"
          data-testid="refund-pending"
          @update:model-value="setRefundPending($event)"
        />
        <label for="refund-pending">Zwroty do wykonania</label>
      </div>
    </div>

    <Message
      v-if="error"
      data-testid="orders-error"
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
      data-key="orderId"
      :pt="{ bodyRow: { 'data-testid': 'order-row' } }"
    >
      <template #empty>
        <span data-testid="empty">{{ emptyText }}</span>
      </template>
      <Column header="Numer">
        <template #body="{ data }">
          <RouterLink
            data-testid="number"
            :to="{ name: 'order-detail', params: { orderId: data.orderId } }"
          >
            {{ data.number }}
          </RouterLink>
        </template>
      </Column>
      <Column header="Data">
        <template #body="{ data }">
          <span data-testid="placed-at">{{ formatDateTime(data.placedAt) }}</span>
        </template>
      </Column>
      <Column header="Klient">
        <template #body="{ data }">
          <span data-testid="full-name">{{ data.fullName }}</span>
        </template>
      </Column>
      <Column header="E-mail">
        <template #body="{ data }">
          <span data-testid="email">{{ data.email }}</span>
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag
            data-testid="status"
            :value="statusLabel(data.status).label"
            :severity="statusLabel(data.status).severity"
          />
        </template>
      </Column>
      <Column
        header="Kwota"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="total">{{ formatMoney(data.totalGross, data.currency) }}</span>
        </template>
      </Column>
      <Column
        v-if="refundPending"
        header="Kwota zwrotu"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="refund-amount">{{ data.refund ? formatMoney(data.refund.amount, data.currency) : "—" }}</span>
        </template>
      </Column>
      <Column
        v-if="refundPending"
        header="Termin zwrotu"
      >
        <template #body="{ data }">
          <div class="refund-due">
            <span data-testid="refund-due">{{ data.refund ? formatDateTime(data.refund.dueAt) : "—" }}</span>
            <Tag
              v-if="data.refund?.overdue"
              data-testid="refund-overdue"
              value="Po terminie"
              severity="danger"
            />
          </div>
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
.orders {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 18rem;
}

.hint {
  color: var(--p-text-muted-color);
}

.status-filter {
  min-width: 14rem;
}

.switch {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-height: 2.5rem;
}

.amount {
  text-align: right;
  white-space: nowrap;
}

.refund-due {
  display: flex;
  align-items: center;
  gap: 0.5rem;
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
