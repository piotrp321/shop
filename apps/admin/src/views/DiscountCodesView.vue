<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import SelectButton from "primevue/selectbutton";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import { computed, nextTick, ref, useTemplateRef, watch } from "vue";
import { RouterLink } from "vue-router";
import { formatDiscount, formatPeriod, formatUses, STATUS_FILTER_OPTIONS, statusLabel, statusSeverity } from "@/discount-codes/format";
import { canManageDiscountCodes } from "@/discount-codes/permissions";
import { type DiscountCodeStatus, MAX_SEARCH_LENGTH, useDiscountCodeList } from "@/discount-codes/useDiscountCodeList";
import { formatMoney } from "@/prices/format";
import { CURRENCY } from "@/promotions/format";
import { useAuthStore } from "@/stores/auth";

const {
  search,
  searchTooLong,
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
  goNext,
  goPrevious,
  retry,
} = useDiscountCodeList();

// The pager buttons stay on while a page loads (a repeated press asks for the same page again, the newest answer wins),
// because a focused button that goes off drops the keyboard focus on the page. A button that is off after the move
// (the last or the first page) hands the focus to the other one, which is on then.
const pagerElement = useTemplateRef<HTMLElement>("pager");

async function movePage(move: () => Promise<void>): Promise<void> {
  await move();
  await nextTick();
  const focused = document.activeElement;
  const buttons = Array.from(pagerElement.value?.querySelectorAll<HTMLButtonElement>("button") ?? []);
  const lost = focused === document.body || buttons.some((button) => button === focused && button.disabled);
  if (lost) {
    buttons.find((button) => !button.disabled)?.focus();
  }
}

// Same for the retry button: it stays on while the list loads (a repeated press asks again, the newest answer wins).
// When the retry succeeds the error, and the button with it, disappears, so the focus goes to the list: to the first
// code, or to the pager when the page has none, or to the search field when there is no other page either (an active
// filter or search found nothing, so the user will change it). When it fails the button is still there and keeps the focus.
const rootElement = useTemplateRef<HTMLElement>("root");

async function retryList(): Promise<void> {
  await retry();
  await nextTick();
  const focused = document.activeElement;
  if (error.value !== null || (focused !== document.body && focused !== null && focused.isConnected)) {
    return;
  }
  const firstCode = rootElement.value?.querySelector<HTMLElement>("[data-testid=code-link]");
  const target =
    firstCode ??
    pagerElement.value?.querySelector<HTMLButtonElement>("button:not(:disabled)") ??
    rootElement.value?.querySelector<HTMLElement>("[data-testid=search]");
  target?.focus();
}

const statusFilter = ref<"all" | DiscountCodeStatus>("all");
watch(statusFilter, (value) => selectStatus(value === "all" ? null : value));

// Only hides the action: the API checks the permission. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canAdd = computed(() => canManageDiscountCodes(auth.staff));
</script>

<template>
  <div
    ref="root"
    class="discount-codes"
  >
    <div class="header">
      <h1>Kody rabatowe</h1>
      <RouterLink
        v-if="canAdd"
        v-slot="{ href, navigate }"
        :to="{ name: 'discount-code-new' }"
        custom
      >
        <Button
          as="a"
          data-testid="add-discount-code"
          :href="href"
          label="Dodaj kod"
          icon="pi pi-plus"
          @click="navigate"
        />
      </RouterLink>
    </div>

    <div class="filters">
      <div class="field">
        <InputText
          v-model="search"
          data-testid="search"
          type="search"
          :maxlength="MAX_SEARCH_LENGTH"
          placeholder="Szukaj po początku kodu"
          aria-label="Szukaj po początku kodu"
          autocomplete="off"
        />
        <div aria-live="polite">
          <small
            v-if="searchTooLong"
            data-testid="search-hint"
            class="hint"
          >
            Kod ma najwyżej {{ MAX_SEARCH_LENGTH }} znaki.
          </small>
        </div>
      </div>
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
      data-testid="discount-codes-error"
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
        @click="retryList()"
      />
    </Message>

    <DataTable
      v-if="loaded"
      :value="items"
      :loading="loading"
      data-key="id"
      :pt="{ bodyRow: { 'data-testid': 'discount-code-row' } }"
    >
      <template #empty>
        <span data-testid="empty">{{ emptyText }}</span>
      </template>
      <Column header="Kod">
        <template #body="{ data }">
          <RouterLink
            data-testid="code-link"
            :to="{ name: 'discount-code', params: { id: data.id } }"
          >
            {{ data.code }}
          </RouterLink>
        </template>
      </Column>
      <Column
        header="Rabat"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="code-discount">{{ formatDiscount(data.kind, data.value) }}</span>
        </template>
      </Column>
      <Column
        header="Minimum"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="code-minimum">{{ data.minimumOrderGross === null ? "—" : formatMoney(data.minimumOrderGross, CURRENCY) }}</span>
        </template>
      </Column>
      <Column header="Okres">
        <template #body="{ data }">
          <span data-testid="code-period">{{ formatPeriod(data.startsAt, data.endsAt) }}</span>
        </template>
      </Column>
      <Column
        header="Użycia"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="code-uses">{{ formatUses(data.activeUses, data.usageLimit) }}</span>
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag
            data-testid="code-status"
            :severity="statusSeverity(data.status)"
            :value="statusLabel(data.status)"
          />
        </template>
      </Column>
    </DataTable>
    <Skeleton
      v-else-if="loading"
      height="16rem"
    />

    <div
      v-if="loaded"
      ref="pager"
      class="pager"
    >
      <Button
        data-testid="previous-page"
        label="Poprzednia"
        icon="pi pi-chevron-left"
        severity="secondary"
        :disabled="!hasPrevious"
        @click="movePage(goPrevious)"
      />
      <span
        data-testid="page-number"
        class="page-number"
        aria-live="polite"
        aria-atomic="true"
      >Strona {{ pageNumber }}</span>
      <Button
        data-testid="next-page"
        label="Następna"
        icon="pi pi-chevron-right"
        icon-pos="right"
        severity="secondary"
        :disabled="!hasNext"
        @click="movePage(goNext)"
      />
    </div>
  </div>
</template>

<style scoped>
.discount-codes {
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

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.hint {
  color: var(--p-text-muted-color);
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
