<script setup lang="ts">
import { FolderTree, ImageOff, Package } from "lucide-vue-next";
import KpiTile from "./KpiTile.vue";
import type { Dashboard } from "./useDashboard";

defineProps<{ catalog: Dashboard["catalog"] }>();

const number = new Intl.NumberFormat("pl-PL");
</script>

<template>
  <section
    aria-label="Katalog"
    class="grid grid-cols-1 gap-6 xl:grid-cols-3"
  >
    <KpiTile
      title="Produkty"
      :icon="Package"
      tone="blue"
      :value="number.format(catalog.productCount)"
      :to="{ name: 'products' }"
      :footer="{ label: 'Bez zdjęć', value: number.format(catalog.productsWithoutImages) }"
    >
      <template #value>
        <span data-testid="product-count">{{ number.format(catalog.productCount) }}</span>
      </template>
    </KpiTile>
    <KpiTile
      title="Kategorie"
      :icon="FolderTree"
      tone="violet"
      :value="number.format(catalog.categoryCount)"
    >
      <template #value>
        <span data-testid="category-count">{{ number.format(catalog.categoryCount) }}</span>
      </template>
    </KpiTile>
    <KpiTile
      title="Bez zdjęć"
      :icon="ImageOff"
      tone="amber"
      :value="number.format(catalog.productsWithoutImages)"
      :footer="catalog.productsWithoutImages > 0 ? { label: 'Uzupełnij zdjęcia w formularzu produktu', testId: 'images-warning' } : undefined"
    >
      <template #value>
        <span data-testid="products-without-images">{{ number.format(catalog.productsWithoutImages) }}</span>
      </template>
    </KpiTile>
  </section>
</template>
