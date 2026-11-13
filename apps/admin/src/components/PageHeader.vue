<script setup lang="ts">
import { ref } from "vue";
import type { RouteLocationRaw } from "vue-router";
import { useRouter } from "vue-router";
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from "@/components/ui/breadcrumb";

export interface Crumb {
  label: string;
  /** Without a target the crumb is plain text (a group of the menu, or the current page when it is the last). */
  to?: RouteLocationRaw;
}

/**
 * The header of a screen: the H1 on the left and the crumbs on the right. The crumbs start with „Pulpit” (added here)
 * and `crumbs` ends with the current page (a form or a detail ends with the name of the object), shown without a link
 * and with `aria-current="page"`. `focus()` moves the focus to the H1, for the end of an action that removed its button.
 */
defineProps<{ title: string; crumbs: Crumb[] }>();

const router = useRouter();
const heading = ref<HTMLElement | null>(null);

// A plain link, not RouterLink: RouterLink marks the link of the page it points at with aria-current, and in the crumbs
// only the last one is the current page.
function follow(event: MouseEvent, to: RouteLocationRaw) {
  if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
    return;
  }
  event.preventDefault();
  void router.push(to);
}
defineExpose({ focus: () => heading.value?.focus() });
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
    <h1
      ref="heading"
      tabindex="-1"
      class="text-xl font-bold text-heading focus:outline-none"
    >
      {{ title }}
    </h1>
    <Breadcrumb aria-label="Okruszki">
      <BreadcrumbList>
        <BreadcrumbItem>
          <BreadcrumbLink
            :href="router.resolve({ name: 'dashboard' }).href"
            @click="follow($event, { name: 'dashboard' })"
          >
            Pulpit
          </BreadcrumbLink>
        </BreadcrumbItem>
        <template
          v-for="(crumb, index) in crumbs"
          :key="index"
        >
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbPage v-if="index === crumbs.length - 1">
              {{ crumb.label }}
            </BreadcrumbPage>
            <BreadcrumbLink
              v-else-if="crumb.to"
              :href="router.resolve(crumb.to).href"
              @click="follow($event, crumb.to)"
            >
              {{ crumb.label }}
            </BreadcrumbLink>
            <span v-else>{{ crumb.label }}</span>
          </BreadcrumbItem>
        </template>
      </BreadcrumbList>
    </Breadcrumb>
  </div>
</template>
