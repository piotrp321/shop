<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";
import { type StaffMe, useAuthStore } from "@/stores/auth";

type Role = StaffMe["roles"][number];

interface MenuItem {
  label: string;
  icon: string;
  to: { name: string };
  /** The roles that see the item; without it everyone does. Only hides the link: the API checks the access. */
  roles?: Role[];
}

const allItems: MenuItem[] = [
  { label: "Dashboard", icon: "pi pi-home", to: { name: "dashboard" } },
  { label: "Produkty", icon: "pi pi-shopping-bag", to: { name: "products" } },
  { label: "Ceny", icon: "pi pi-tag", to: { name: "prices" } },
  { label: "Promocje", icon: "pi pi-percentage", to: { name: "promotions" } },
  { label: "Kody rabatowe", icon: "pi pi-ticket", to: { name: "discount-codes" } },
  { label: "Stany magazynowe", icon: "pi pi-box", to: { name: "inventory" } },
  { label: "Zamówienia", icon: "pi pi-shopping-cart", to: { name: "orders" }, roles: ["admin", "support", "warehouse"] },
];

// The roles come from /api/v1/admin/me; until they are known the items that need a role stay hidden.
const auth = useAuthStore();
const items = computed(() =>
  allItems.filter((item) => item.roles === undefined || (auth.staff?.roles.some((role) => item.roles?.includes(role)) ?? false)),
);
</script>

<template>
  <!-- PrimeVue variables keep today's look until the shell of ADMIN-DESIGN moves the menu onto the tokens. -->
  <nav
    class="flex flex-col gap-1 px-3 py-4"
    aria-label="Menu główne"
  >
    <RouterLink
      v-for="item in items"
      :key="item.label"
      :to="item.to"
      class="flex items-center gap-3 rounded-(--p-content-border-radius) px-3 py-2.5 text-(color:--p-text-color) no-underline hover:bg-(color:--p-content-hover-background)"
      active-class="menu-item--active bg-(color:--p-highlight-background)! text-(color:--p-highlight-color)!"
    >
      <i :class="item.icon" />
      <span>{{ item.label }}</span>
    </RouterLink>
  </nav>
</template>
