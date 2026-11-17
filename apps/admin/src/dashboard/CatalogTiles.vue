<script setup lang="ts">
import { computed } from "vue";
import { FolderTree, ImageOff, Package } from "lucide-vue-next";
import KpiTile from "./KpiTile.vue";
import { formatCount } from "./todo";
import type { Dashboard } from "./useDashboard";

const props = defineProps<{ catalog: Dashboard["catalog"]; queues: Dashboard["queues"] }>();

const number = new Intl.NumberFormat("pl-PL");
const millions = new Intl.NumberFormat("pl-PL", { notation: "compact", maximumFractionDigits: 1 });

/** The estimated number of products: whole numbers up to a million, then „10 mln”; „—” without an estimate. */
const products = computed(() => {
  const count = props.catalog.productCount;
  if (count === null) {
    return "—";
  }
  return count.value >= 1_000_000 ? millions.format(count.value) : number.format(count.value);
});

const withoutImages = computed(() => props.queues.productsWithoutImages);
const withoutImagesText = computed(() => (withoutImages.value === null ? "" : formatCount(withoutImages.value)));
</script>

<template>
  <section
    aria-label="Katalog"
    :class="['grid grid-cols-1 gap-6', withoutImages === null ? 'xl:grid-cols-2' : 'xl:grid-cols-3']"
  >
    <KpiTile
      title="Produkty"
      :icon="Package"
      tone="blue"
      :value="products"
      :to="{ name: 'products' }"
      :estimated="catalog.productCount !== null"
      :description="catalog.productCount === null ? 'Liczba produktów będzie dostępna po aktualizacji statystyk bazy.' : undefined"
      :footer="withoutImages === null ? undefined : { label: 'Bez zdjęć', value: withoutImagesText }"
    >
      <template #value>
        <span data-testid="product-count">{{ products }}</span>
      </template>
    </KpiTile>
    <KpiTile
      title="Kategorie"
      :icon="FolderTree"
      tone="violet"
      :value="number.format(catalog.categoryCount)"
      :to="{ path: '/categories' }"
    >
      <template #value>
        <span data-testid="category-count">{{ number.format(catalog.categoryCount) }}</span>
      </template>
    </KpiTile>
    <KpiTile
      v-if="withoutImages !== null"
      title="Bez zdjęć"
      :icon="ImageOff"
      tone="amber"
      :value="withoutImagesText"
      :to="{ name: 'products', query: { images: 'none' } }"
      :footer="withoutImages.value > 0 ? { label: 'Uzupełnij zdjęcia w formularzu produktu', testId: 'images-warning' } : undefined"
    >
      <template #value>
        <span data-testid="products-without-images">{{ withoutImagesText }}</span>
      </template>
    </KpiTile>
  </section>
</template>
