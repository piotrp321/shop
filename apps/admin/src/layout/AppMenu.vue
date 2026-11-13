<script setup lang="ts">
import { computed, provide, reactive, watch } from "vue";
import { useRoute } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import AppMenuItem from "./AppMenuItem.vue";
import { currentLeaf, menuFor } from "./menu";
import { MENU_CONTEXT } from "./menuContext";

const emit = defineEmits<{ navigate: [] }>();

const auth = useAuthStore();
const route = useRoute();

// The roles come from /api/v1/admin/me; until they are known the items that need a role stay hidden.
const sections = computed(() => menuFor(auth.staff));
const current = computed(() => currentLeaf(sections.value, route));

// The groups the staff member opened or the route led into. A group opens when the current item is inside it (on
// entering and on every move), and closes only by its button.
const open = reactive(new Set<string>());
watch(
  current,
  (found) => {
    for (const group of found?.groups ?? []) {
      open.add(group);
    }
  },
  { immediate: true },
);

provide(MENU_CONTEXT, {
  currentId: computed(() => current.value?.leaf.id ?? null),
  isOpen: (groupId) => open.has(groupId),
  toggle: (groupId) => {
    if (open.has(groupId)) {
      open.delete(groupId);
    } else {
      open.add(groupId);
    }
  },
  chosen: () => emit("navigate"),
});
</script>

<template>
  <nav
    class="flex flex-col gap-5 px-3 py-4"
    aria-label="Menu główne"
  >
    <div
      v-for="section in sections"
      :key="section.id"
      class="flex flex-col gap-1"
    >
      <p
        data-testid="menu-section"
        class="px-3 pb-1 text-xs font-bold tracking-widest text-sidebar-section uppercase"
      >
        {{ section.title }}
      </p>
      <ul class="flex flex-col gap-1">
        <AppMenuItem
          v-for="entry in section.entries"
          :key="entry.id"
          :entry="entry"
          :depth="0"
        />
      </ul>
    </div>
  </nav>
</template>
