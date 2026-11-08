<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import SelectButton from "primevue/selectbutton";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import TreeSelect from "primevue/treeselect";
import { computed, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { formatDateTime } from "@/prices/format";
import { canManageProducts } from "@/products/permissions";
import { thumbnailSources } from "@/products/thumbnail";
import { MAX_SEARCH_LENGTH, type ProductStatus, useProductList } from "@/products/useProductList";
import { useAuthStore } from "@/stores/auth";

const {
  search,
  searchTooShort,
  items,
  loading,
  loaded,
  error,
  hasPrevious,
  hasNext,
  pageNumber,
  selectCategory,
  selectStatus,
  goNext,
  goPrevious,
  retry,
} = useProductList();
const { nodes: categories, error: categoriesError } = useCategoryTree();

// TreeSelect (single selection) holds the chosen node as { [key]: true }, or null when nothing is chosen.
const selection = ref<Record<string, boolean> | null>(null);
watch(selection, (value) => selectCategory(value ? (Object.keys(value)[0] ?? null) : null));

const statusOptions = [
  { label: "Wszystkie", value: "all" },
  { label: "Szkice", value: "draft" },
  { label: "Opublikowane", value: "published" },
];
const statusFilter = ref<"all" | ProductStatus>("all");
watch(statusFilter, (value) => selectStatus(value === "all" ? null : value));

// Only hides the action: the API checks the permission. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canAdd = computed(() => canManageProducts(auth.staff));

const statusLabels: Record<ProductStatus, string> = { draft: "Szkic", published: "Opublikowany" };
</script>

<template>
  <div class="products">
    <div class="header">
      <h1>Produkty</h1>
      <RouterLink
        v-if="canAdd"
        v-slot="{ href, navigate }"
        :to="{ name: 'product-new' }"
        custom
      >
        <Button
          as="a"
          data-testid="add-product"
          :href="href"
          label="Dodaj produkt"
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
          placeholder="Szukaj po nazwie"
          aria-label="Szukaj po nazwie produktu"
        />
        <small
          v-if="searchTooShort"
          data-testid="search-hint"
          class="hint"
        >
          Wpisz co najmniej 2 znaki, aby wyszukać.
        </small>
      </div>
      <TreeSelect
        v-model="selection"
        data-testid="category-filter"
        class="category-filter"
        :options="categories"
        selection-mode="single"
        placeholder="Wszystkie kategorie"
        show-clear
        filter
        :disabled="categories.length === 0"
        aria-label="Filtr kategorii"
      />
      <SelectButton
        v-model="statusFilter"
        data-testid="status-filter"
        :options="statusOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        aria-label="Filtr statusu"
      />
    </div>

    <Message
      v-if="categoriesError"
      data-testid="categories-error"
      severity="warn"
    >
      {{ categoriesError }}
    </Message>

    <Message
      v-if="error"
      data-testid="products-error"
      severity="error"
    >
      {{ error }}
      <Button
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
      :pt="{ bodyRow: { 'data-testid': 'product-row' } }"
    >
      <template #empty>
        <span data-testid="empty">Brak produktów spełniających kryteria.</span>
      </template>
      <Column header="Zdjęcie">
        <template #body="{ data }">
          <img
            v-if="data.image && thumbnailSources(data.image.variants)"
            data-testid="product-image"
            class="thumbnail"
            :src="thumbnailSources(data.image.variants)!.src"
            :srcset="thumbnailSources(data.image.variants)!.srcset"
            sizes="48px"
            width="48"
            height="48"
            :alt="data.image.alt"
          >
        </template>
      </Column>
      <Column header="Nazwa">
        <template #body="{ data }">
          <RouterLink
            data-testid="product-link"
            :to="{ name: 'product', params: { id: data.id } }"
          >
            {{ data.name }}
          </RouterLink>
        </template>
      </Column>
      <Column header="Kategoria główna">
        <template #body="{ data }">
          <span data-testid="product-category">{{ data.mainCategory.name }}</span>
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
      <Column header="Status">
        <template #body="{ data }">
          <Tag
            data-testid="product-status"
            :severity="data.status === 'published' ? 'success' : 'secondary'"
            :value="statusLabels[data.status as ProductStatus]"
          />
        </template>
      </Column>
      <Column header="Ostatnia zmiana">
        <template #body="{ data }">
          <span data-testid="product-updated">{{ formatDateTime(data.updatedAt) }}</span>
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
.products {
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
  min-width: 18rem;
}

.hint {
  color: var(--p-text-muted-color);
}

.category-filter {
  min-width: 18rem;
}

.thumbnail {
  display: block;
  border-radius: var(--p-content-border-radius);
  object-fit: cover;
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
