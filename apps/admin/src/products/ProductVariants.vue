<script setup lang="ts">
import Message from "primevue/message";
import type { VariantRow } from "./useProductForm";

defineProps<{
  axes: string[];
  rows: VariantRow[];
  errors: string[];
}>();
</script>

<template>
  <section
    data-testid="variants"
    class="variants"
    aria-labelledby="variants-heading"
  >
    <h2 id="variants-heading">
      Warianty
    </h2>
    <Message
      v-for="title in errors"
      :key="title"
      data-testid="variants-error"
      severity="error"
    >
      {{ title }}
    </Message>
    <table class="table">
      <thead v-if="axes.length > 0">
        <tr>
          <th
            v-for="axis in axes"
            :key="axis"
            scope="col"
          >
            {{ axis }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(row, index) in rows"
          :key="row.variantId ?? `new-${index}`"
          data-testid="variant-row"
        >
          <td
            v-if="axes.length === 0"
          >
            Wariant domyślny
          </td>
          <td
            v-for="(value, position) in row.values"
            :key="position"
          >
            {{ value }}
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.variants {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.table {
  border-collapse: collapse;
  width: 100%;
}

.table th,
.table td {
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
  text-align: left;
}
</style>
