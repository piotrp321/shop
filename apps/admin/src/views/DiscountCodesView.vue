<script setup lang="ts">
import { Pencil, Plus } from "lucide-vue-next";
import { computed, nextTick, ref, useId } from "vue";
import { RouterLink } from "vue-router";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import ListEmpty from "@/components/list/ListEmpty.vue";
import ListPager from "@/components/list/ListPager.vue";
import ListToolbar from "@/components/list/ListToolbar.vue";
import RowActions, { type RowAction } from "@/components/list/RowActions.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { formatDiscount, formatPeriod, formatUses, STATUS_FILTER_OPTIONS, statusLabel, statusVariant } from "@/discount-codes/format";
import { canManageDiscountCodes } from "@/discount-codes/permissions";
import { EMPTY_MESSAGE, MAX_SEARCH_LENGTH, type DiscountCodeListItem, type DiscountCodeStatus, useDiscountCodeList } from "@/discount-codes/useDiscountCodeList";
import { formatMoney } from "@/prices/format";
import { CURRENCY } from "@/promotions/format";
import { useAuthStore } from "@/stores/auth";

const list = useDiscountCodeList();
const { search, searchHint, query, filtered, items, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range, total } = list;

// Only hides the action: the API checks the permission. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canAdd = computed(() => canManageDiscountCodes(auth.staff));

const statusFilterId = useId();
const statusFilterLabel = computed(() => STATUS_FILTER_OPTIONS.find((option) => option.value === (query.value.status ?? "all"))?.label);

function selectStatus(value: unknown): void {
  const option = STATUS_FILTER_OPTIONS.find((candidate) => candidate.value === value);
  list.selectStatus(option === undefined || option.value === "all" ? null : (option.value as DiscountCodeStatus));
}

const minimumOf = (code: DiscountCodeListItem) => (code.minimumOrderGross === null ? "—" : formatMoney(code.minimumOrderGross, CURRENCY));

const columns: ListColumn<DiscountCodeListItem>[] = [
  { key: "code", header: "Kod", main: true },
  { key: "discount", header: "Rabat", align: "right" },
  { key: "minimum", header: "Minimum", hideBelow: "lg", align: "right", text: minimumOf },
  { key: "period", header: "Okres", hideBelow: "lg", text: (code) => formatPeriod(code.startsAt, code.endsAt) },
  { key: "uses", header: "Użycia", align: "right" },
  { key: "status", header: "Status" },
  { key: "actions", header: "Akcje", align: "right" },
];

// „Edytuj” only for a role that can change codes: the code still opens the form, read-only.
function actionsOf(code: DiscountCodeListItem): RowAction[] {
  return canAdd.value ? [{ key: "edit", label: "Edytuj", icon: Pencil, to: { name: "discount-code", params: { id: code.id } } }] : [];
}

const toolbar = ref<InstanceType<typeof ListToolbar> | null>(null);

// „Wyczyść filtry” goes away with the filters, so the focus goes to the search field.
async function clearFilters() {
  list.clearFilters();
  await nextTick();
  toolbar.value?.focusSearch();
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      title="Kody rabatowe"
      :crumbs="[{ label: 'Marketing' }, { label: 'Kody rabatowe' }]"
    />

    <Card class="gap-0 py-0">
      <ListToolbar
        ref="toolbar"
        v-model:search="search"
        search-label="Szukaj po początku kodu"
        search-placeholder="Szukaj po początku kodu"
        :search-max="MAX_SEARCH_LENGTH"
        :search-hint="searchHint"
        :filtered="filtered"
        :per-page="query.perPage"
        @update:per-page="list.setPerPage"
        @clear="clearFilters"
      >
        <template #filters>
          <div class="flex flex-col gap-1.5">
            <Label :for="statusFilterId">
              Status
            </Label>
            <Select
              :model-value="query.status ?? 'all'"
              @update:model-value="selectStatus"
            >
              <SelectTrigger
                :id="statusFilterId"
                data-testid="status-filter"
                class="w-44"
              >
                <SelectValue>{{ statusFilterLabel }}</SelectValue>
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in STATUS_FILTER_OPTIONS"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
        </template>
        <template
          v-if="canAdd"
          #actions
        >
          <Button as-child>
            <RouterLink
              data-testid="add-discount-code"
              :to="{ name: 'discount-code-new' }"
            >
              <Plus aria-hidden="true" />
              Dodaj kod
            </RouterLink>
          </Button>
        </template>
      </ListToolbar>

      <DataTable
        label="Lista kodów rabatowych"
        :columns="columns"
        :rows="items"
        :row-key="(code: DiscountCodeListItem) => code.id"
        row-testid="discount-code-row"
        error-testid="discount-codes-error"
        :loading="loading"
        :loaded="loaded"
        :error="error"
        :forbidden="forbidden"
        :retry="list.retry"
      >
        <template #cell-code="{ row }">
          <RouterLink
            data-testid="code-link"
            class="rounded-sm font-bold break-all text-heading hover:underline"
            :to="{ name: 'discount-code', params: { id: row.id } }"
          >
            {{ row.code }}
          </RouterLink>
        </template>
        <template #cell-discount="{ row }">
          <span
            data-testid="code-discount"
            class="tabular-nums"
          >{{ formatDiscount(row.kind, row.value) }}</span>
        </template>
        <template #cell-minimum="{ row }">
          <span
            data-testid="code-minimum"
            class="tabular-nums"
          >{{ minimumOf(row) }}</span>
        </template>
        <template #cell-period="{ row }">
          <span data-testid="code-period">{{ formatPeriod(row.startsAt, row.endsAt) }}</span>
        </template>
        <template #cell-uses="{ row }">
          <span
            data-testid="code-uses"
            class="tabular-nums"
          >{{ formatUses(row.activeUses, row.usageLimit) }}</span>
        </template>
        <template #cell-status="{ row }">
          <Badge
            data-testid="code-status"
            :variant="statusVariant(row.status)"
          >
            {{ statusLabel(row.status) }}
          </Badge>
        </template>
        <template #cell-actions="{ row }">
          <RowActions
            :name="row.code"
            :actions="actionsOf(row)"
          />
        </template>
        <template #empty>
          <ListEmpty
            :filtered="filtered"
            :later-page="hasPrevious"
            :empty-text="EMPTY_MESSAGE"
            @clear="clearFilters"
          >
            <template
              v-if="canAdd"
              #action
            >
              <Button as-child>
                <RouterLink
                  data-testid="empty-add-discount-code"
                  :to="{ name: 'discount-code-new' }"
                >
                  <Plus aria-hidden="true" />
                  Dodaj kod
                </RouterLink>
              </Button>
            </template>
          </ListEmpty>
        </template>
      </DataTable>

      <div
        v-if="loaded && (items.length > 0 || hasPrevious)"
        class="border-t border-separator px-4 py-3"
      >
        <ListPager
          :has-previous="hasPrevious"
          :has-next="hasNext"
          :page-number="pageNumber"
          :range="range"
          :total="total"
          @previous="list.goPrevious"
          @next="list.goNext"
        />
      </div>
    </Card>
  </div>
</template>
