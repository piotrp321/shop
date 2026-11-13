<script setup lang="ts">
import { ExternalLink, Image as ImageIcon, Pencil, Plus } from "lucide-vue-next";
import { computed, nextTick, ref, useId } from "vue";
import { RouterLink } from "vue-router";
import CategoryCombobox from "@/components/CategoryCombobox.vue";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import ListEmpty from "@/components/list/ListEmpty.vue";
import ListPager from "@/components/list/ListPager.vue";
import ListToolbar from "@/components/list/ListToolbar.vue";
import RowActions, { type RowAction } from "@/components/list/RowActions.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { readUrl } from "@/config";
import { formatDateTime } from "@/prices/format";
import { canManageProducts } from "@/products/permissions";
import { thumbnailSources } from "@/products/thumbnail";
import { EMPTY_MESSAGE, MAX_SEARCH_LENGTH, type ProductListItem, type ProductStatus, useProductList } from "@/products/useProductList";
import { useAuthStore } from "@/stores/auth";

const categories = useCategoryTree();
const list = useProductList({ nodes: categories.nodes, loaded: categories.loaded });
const { search, searchHint, query, filtered, items, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range } = list;

// Only hides the action: the API checks the permission. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canAdd = computed(() => canManageProducts(auth.staff));

const storeUrl = readUrl("VITE_STORE_URL");

const statusLabels: Record<ProductStatus, string> = { draft: "Szkic", published: "Opublikowany" };
const statusFilters = [
  { value: "all", label: "Wszystkie" },
  { value: "draft", label: "Szkice" },
  { value: "published", label: "Opublikowane" },
];
const statusFilterLabel = computed(() => statusFilters.find((option) => option.value === (query.value.status ?? "all"))?.label);
const statusFilterId = useId();

const columns: ListColumn<ProductListItem>[] = [
  { key: "product", header: "Produkt", main: true },
  { key: "category", header: "Kategoria", hideBelow: "lg", text: (product) => product.mainCategory.name },
  { key: "variants", header: "Warianty", align: "right" },
  { key: "status", header: "Status" },
  { key: "updated", header: "Ostatnia zmiana", hideBelow: "md", text: (product) => formatDateTime(product.updatedAt) },
  { key: "actions", header: "Akcje", align: "right" },
];

// „Edytuj” only for a role that can change products: the name of the product still opens the form, read-only.
function actionsOf(product: ProductListItem): RowAction[] {
  const actions: RowAction[] = canAdd.value ? [{ key: "edit", label: "Edytuj", icon: Pencil, to: { name: "product", params: { id: product.id } } }] : [];
  if (product.status === "published") {
    actions.push({ key: "preview", label: "Podgląd w sklepie", icon: ExternalLink, href: `${storeUrl}/p/${encodeURIComponent(product.slug)}`, external: true });
  }
  return actions;
}

// The date, and under it the time with the seconds; the text of the whole stays „date, time” for a reader.
function dateAndTime(moment: string): { date: string; time: string | null } {
  const [date = "", time] = formatDateTime(moment).split(", ");
  return { date, time: time ?? null };
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
      title="Produkty"
      :crumbs="[{ label: 'Katalog' }, { label: 'Produkty' }]"
    />

    <Card class="gap-0 py-0">
      <ListToolbar
        ref="toolbar"
        v-model:search="search"
        search-label="Szukaj po nazwie produktu"
        search-placeholder="Szukaj po nazwie"
        :search-max="MAX_SEARCH_LENGTH"
        :search-hint="searchHint"
        :filtered="filtered"
        :per-page="query.perPage"
        @update:per-page="list.setPerPage"
        @clear="clearFilters"
      >
        <template #filters>
          <CategoryCombobox
            data-testid="category-filter"
            class="w-full sm:w-64"
            label="Kategoria"
            placeholder="Wszystkie kategorie"
            :nodes="categories.nodes.value"
            :model-value="query.category"
            deselectable
            :disabled="categories.nodes.value.length === 0"
            @update:model-value="list.selectCategory(($event as string | null) ?? null)"
          />
          <div class="flex flex-col gap-1.5">
            <Label :for="statusFilterId">
              Status
            </Label>
            <Select
              :model-value="query.status ?? 'all'"
              @update:model-value="list.selectStatus($event === 'draft' || $event === 'published' ? $event : null)"
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
                  v-for="option in statusFilters"
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
              data-testid="add-product"
              :to="{ name: 'product-new' }"
            >
              <Plus aria-hidden="true" />
              Dodaj produkt
            </RouterLink>
          </Button>
        </template>
      </ListToolbar>

      <Alert
        v-if="categories.error.value"
        data-testid="categories-error"
        class="m-4 w-auto"
      >
        {{ categories.error.value }}
      </Alert>

      <DataTable
        label="Lista produktów"
        :columns="columns"
        :rows="items"
        :row-key="(product: ProductListItem) => product.id"
        row-testid="product-row"
        :loading="loading"
        :loaded="loaded"
        :error="error"
        :forbidden="forbidden"
        :retry="list.retry"
      >
        <template #lead="{ row }">
          <img
            v-if="row.image && thumbnailSources(row.image.variants)"
            data-testid="product-image"
            class="size-10.5 shrink-0 rounded-md object-cover"
            :src="thumbnailSources(row.image.variants)!.src"
            :srcset="thumbnailSources(row.image.variants)!.srcset"
            sizes="42px"
            width="42"
            height="42"
            :alt="row.image.alt"
          >
          <span
            v-else
            data-testid="product-placeholder"
            class="flex size-10.5 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
          >
            <ImageIcon
              class="size-5"
              aria-hidden="true"
            />
          </span>
        </template>
        <template #cell-product="{ row }">
          <RouterLink
            data-testid="product-link"
            class="rounded-sm font-bold break-words text-heading hover:underline"
            :to="{ name: 'product', params: { id: row.id } }"
          >
            {{ row.name }}
          </RouterLink>
          <p
            data-testid="product-slug"
            class="text-xs break-all text-muted-foreground"
          >
            {{ row.slug }}
          </p>
        </template>
        <template #cell-category="{ row }">
          <span data-testid="product-category">{{ row.mainCategory.name }}</span>
        </template>
        <template #cell-variants="{ row }">
          <span
            data-testid="variant-count"
            class="tabular-nums"
          >{{ row.variantCount }}</span>
        </template>
        <template #cell-status="{ row }">
          <Badge
            data-testid="product-status"
            :variant="row.status === 'published' ? 'success' : 'warning'"
          >
            {{ statusLabels[row.status] }}
          </Badge>
        </template>
        <template #cell-updated="{ row }">
          <span data-testid="product-updated">
            <span>{{ dateAndTime(row.updatedAt).date }}</span><template v-if="dateAndTime(row.updatedAt).time"><span class="sr-only">, </span><span class="block text-xs text-muted-foreground">{{ dateAndTime(row.updatedAt).time }}</span></template>
          </span>
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
                  data-testid="empty-add-product"
                  :to="{ name: 'product-new' }"
                >
                  <Plus aria-hidden="true" />
                  Dodaj produkt
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
