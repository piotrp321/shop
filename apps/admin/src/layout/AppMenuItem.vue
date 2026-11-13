<script setup lang="ts">
import { ChevronDown } from "lucide-vue-next";
import { inject } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { cn } from "@/lib/utils";
import type { MenuEntry } from "./menu";
import { MENU_CONTEXT } from "./menuContext";

const props = defineProps<{ entry: MenuEntry; depth: number }>();

const injected = inject(MENU_CONTEXT);
if (injected === undefined) {
  throw new Error("AppMenuItem needs the context of AppMenu.");
}
const menu = injected;

const router = useRouter();
const route = useRoute();

/** A click the browser handles itself (a new tab or window, a download): the panel does not navigate nor close the drawer. */
function leftToBrowser(event: MouseEvent): boolean {
  return event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
}

/**
 * A choice of an item. The location of the item may be the current one while the open list holds filters set in it
 * (they are not in the address): the router would drop such a push as a duplicate, so it is forced, which gives the
 * list a new route to read its filters from. It replaces the entry, so the history gets no copy of the page.
 */
function choose(event: MouseEvent, navigate: (event?: MouseEvent) => Promise<unknown>) {
  if (props.entry.kind !== "leaf" || leftToBrowser(event)) {
    return;
  }
  const target = router.resolve(props.entry.to);
  if (target.fullPath === route.fullPath) {
    event.preventDefault();
    void router.push({ path: target.path, query: target.query, hash: target.hash, force: true, replace: true });
  } else {
    void navigate(event);
  }
  menu.chosen();
}

// Indentation by level: the items of the second and the third level are set in under their group.
const INDENT = ["pl-3", "pl-10", "pl-14"];
const indent = (depth: number) => INDENT[Math.min(depth, INDENT.length - 1)];
const ITEM =
  "flex w-full items-center gap-3 rounded-md py-2.5 pr-3 text-left text-sm font-semibold text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-active";
</script>

<template>
  <li v-if="entry.kind === 'group'">
    <div :class="cn('rounded-md', menu.isOpen(entry.id) && 'bg-sidebar-accent')">
      <button
        type="button"
        :class="cn(ITEM, indent(depth), 'hover:bg-sidebar-accent')"
        :aria-expanded="menu.isOpen(entry.id)"
        :aria-controls="menu.regionId(entry.id)"
        @click="menu.toggle(entry.id)"
      >
        <component
          :is="entry.icon"
          v-if="entry.icon"
          class="size-5 shrink-0"
          aria-hidden="true"
        />
        <span class="flex-1">{{ entry.label }}</span>
        <ChevronDown
          :class="cn('size-4 shrink-0 transition-transform', menu.isOpen(entry.id) && 'rotate-180')"
          aria-hidden="true"
        />
      </button>
      <ul
        v-show="menu.isOpen(entry.id)"
        :id="menu.regionId(entry.id)"
        class="flex flex-col gap-1 pb-1"
      >
        <AppMenuItem
          v-for="child in entry.children"
          :key="child.id"
          :entry="child"
          :depth="depth + 1"
        />
      </ul>
    </div>
  </li>
  <li v-else>
    <RouterLink
      v-slot="{ href, navigate }"
      :to="entry.to"
      custom
    >
      <a
        :href="href"
        :aria-current="menu.currentId.value === entry.id ? 'page' : undefined"
        :class="cn(ITEM, indent(depth), 'aria-[current=page]:bg-sidebar-accent aria-[current=page]:font-bold aria-[current=page]:text-sidebar-active')"
        @click="choose($event, navigate)"
      >
        <component
          :is="entry.icon"
          v-if="entry.icon"
          class="size-5 shrink-0"
          aria-hidden="true"
        />
        <span>{{ entry.label }}</span>
      </a>
    </RouterLink>
  </li>
</template>
