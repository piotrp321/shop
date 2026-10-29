<script setup lang="ts">
import { RouterLink } from "vue-router";

const items = [{ label: "Dashboard", icon: "pi pi-home", to: { name: "dashboard" } }];
</script>

<template>
  <nav
    class="menu"
    aria-label="Menu główne"
  >
    <RouterLink
      v-for="item in items"
      :key="item.label"
      :to="item.to"
      class="menu-item"
      active-class="menu-item--active"
    >
      <i :class="item.icon" />
      <span>{{ item.label }}</span>
    </RouterLink>
  </nav>
</template>

<style scoped>
.menu {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 1rem 0.75rem;
}

.menu-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.625rem 0.75rem;
  border-radius: var(--p-content-border-radius);
  color: var(--p-text-color);
  text-decoration: none;
}

.menu-item:hover {
  background: var(--p-content-hover-background);
}

.menu-item--active {
  background: var(--p-highlight-background);
  color: var(--p-highlight-color);
}
</style>
