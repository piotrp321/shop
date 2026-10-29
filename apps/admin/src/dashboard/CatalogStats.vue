<script setup lang="ts">
import Card from "primevue/card";
import Tag from "primevue/tag";
import type { Dashboard } from "./useDashboard";

defineProps<{ catalog: Dashboard["catalog"] }>();
</script>

<template>
  <section aria-labelledby="catalog-heading">
    <h2 id="catalog-heading">
      Katalog
    </h2>
    <div class="tiles">
      <Card>
        <template #title>
          Produkty
        </template>
        <template #content>
          <span
            class="value"
            data-testid="product-count"
          >{{ catalog.productCount }}</span>
        </template>
      </Card>
      <Card>
        <template #title>
          Kategorie
        </template>
        <template #content>
          <span
            class="value"
            data-testid="category-count"
          >{{ catalog.categoryCount }}</span>
        </template>
      </Card>
      <Card>
        <template #title>
          Produkty bez zdjęć
        </template>
        <template #content>
          <span
            class="value"
            data-testid="products-without-images"
          >{{ catalog.productsWithoutImages }}</span>
          <Tag
            v-if="catalog.productsWithoutImages > 0"
            data-testid="images-warning"
            severity="warn"
            icon="pi pi-exclamation-triangle"
            value="Uzupełnij zdjęcia"
          />
        </template>
      </Card>
    </div>
  </section>
</template>

<style scoped>
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 1rem;
}

.value {
  font-size: 2rem;
  font-weight: 600;
  margin-right: 0.75rem;
}
</style>
