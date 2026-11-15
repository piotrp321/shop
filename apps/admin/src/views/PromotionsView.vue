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
import { formatDateTime } from "@/prices/format";
import { STATUS_FILTER_OPTIONS, statusLabel, statusVariant } from "@/promotions/format";
import { canManagePromotions } from "@/promotions/permissions";
import { EMPTY_MESSAGE, type PromotionListItem, type PromotionStatus, usePromotionList } from "@/promotions/usePromotionList";
import { useAuthStore } from "@/stores/auth";

const list = usePromotionList();
const { query, filtered, items, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range } = list;

// Only hides the action: the API checks the permission. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canAdd = computed(() => canManagePromotions(auth.staff));

const statusFilterId = useId();
const statusFilterLabel = computed(() => STATUS_FILTER_OPTIONS.find((option) => option.value === (query.value.status ?? "all"))?.label);

function selectStatus(value: unknown): void {
  const option = STATUS_FILTER_OPTIONS.find((candidate) => candidate.value === value);
  list.selectStatus(option === undefined || option.value === "all" ? null : (option.value as PromotionStatus));
}

const columns: ListColumn<PromotionListItem>[] = [
  { key: "name", header: "Nazwa", main: true },
  { key: "percent", header: "Obniżka", align: "right" },
  { key: "starts", header: "Początek", hideBelow: "lg", text: (promotion) => formatDateTime(promotion.startsAt) },
  { key: "ends", header: "Koniec", hideBelow: "lg", text: (promotion) => formatDateTime(promotion.endsAt) },
  { key: "status", header: "Status" },
  { key: "variants", header: "Warianty", align: "right" },
  { key: "actions", header: "Akcje", align: "right" },
];

// „Edytuj” only for a role that can change promotions: the name still opens the form, read-only.
function actionsOf(promotion: PromotionListItem): RowAction[] {
  return canAdd.value ? [{ key: "edit", label: "Edytuj", icon: Pencil, to: { name: "promotion", params: { id: promotion.id } } }] : [];
}

const statusFilter = ref<InstanceType<typeof SelectTrigger> | null>(null);

// „Wyczyść filtry” goes away with the filter, so the focus goes to the first control of the toolbar: the filter.
async function clearFilters() {
  list.clearFilters();
  await nextTick();
  (statusFilter.value?.$el as HTMLElement | undefined)?.focus();
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      title="Promocje"
      :crumbs="[{ label: 'Marketing' }, { label: 'Promocje' }]"
    />

    <Card class="gap-0 py-0">
      <ListToolbar
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
                ref="statusFilter"
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
              data-testid="add-promotion"
              :to="{ name: 'promotion-new' }"
            >
              <Plus aria-hidden="true" />
              Dodaj promocję
            </RouterLink>
          </Button>
        </template>
      </ListToolbar>

      <DataTable
        label="Lista promocji"
        :columns="columns"
        :rows="items"
        :row-key="(promotion: PromotionListItem) => promotion.id"
        row-testid="promotion-row"
        error-testid="promotions-error"
        :loading="loading"
        :loaded="loaded"
        :error="error"
        :forbidden="forbidden"
        :retry="list.retry"
      >
        <template #cell-name="{ row }">
          <RouterLink
            data-testid="promotion-link"
            class="rounded-sm font-bold text-heading hover:underline"
            :to="{ name: 'promotion', params: { id: row.id } }"
          >
            {{ row.name }}
          </RouterLink>
        </template>
        <template #cell-percent="{ row }">
          <span
            data-testid="promotion-percent"
            class="tabular-nums"
          >{{ row.percentOff }}%</span>
        </template>
        <template #cell-starts="{ row }">
          <span data-testid="promotion-starts">{{ formatDateTime(row.startsAt) }}</span>
        </template>
        <template #cell-ends="{ row }">
          <span data-testid="promotion-ends">{{ formatDateTime(row.endsAt) }}</span>
        </template>
        <template #cell-status="{ row }">
          <Badge
            data-testid="promotion-status"
            :variant="statusVariant(row.status)"
          >
            {{ statusLabel(row.status) }}
          </Badge>
        </template>
        <template #cell-variants="{ row }">
          <span
            data-testid="variant-count"
            class="tabular-nums"
          >{{ row.variantCount }}</span>
        </template>
        <template #cell-actions="{ row }">
          <RowActions
            :name="row.name"
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
                  data-testid="empty-add-promotion"
                  :to="{ name: 'promotion-new' }"
                >
                  <Plus aria-hidden="true" />
                  Dodaj promocję
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
          @previous="list.goPrevious"
          @next="list.goNext"
        />
      </div>
    </Card>
  </div>
</template>
