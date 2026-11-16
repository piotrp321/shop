<script lang="ts">
import type { Component } from "vue";
import type { RouteLocationRaw } from "vue-router";

export interface RowAction {
  key: string;
  /** The verb, e.g. „Edytuj”; the accessible name and the tooltip add the name of the object. */
  label: string;
  icon: Component;
  /**
   * A page of the panel (a plain link that the router follows) or `href`, an address outside it. An action with neither
   * is a button: pressing it emits `select` with its key and the button, and the list decides what it does (opens a window).
   */
  to?: RouteLocationRaw;
  href?: string;
  /** Opens in a new tab; the accessible name says so. */
  external?: boolean;
  /** The `data-testid` of the action when it is not `action-<key>`. */
  testid?: string;
}
</script>

<script setup lang="ts">
import { useRouter } from "vue-router";
import { buttonVariants } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * The actions of a row (docs/features/admin-design/spec.md, „Standard listy”): square 34 px buttons with an icon, each
 * with an accessible name that has the name of the object („Edytuj: Buty trailowe Norvane Grań 300”) and a tooltip with
 * it on hover and focus. An action that the role can never do is not passed in at all.
 */
const props = defineProps<{ name: string; actions: RowAction[] }>();
const emit = defineEmits<{ select: [key: string, trigger: HTMLElement] }>();

const router = useRouter();

const NEW_TAB = " (otwiera się w nowej karcie)";

function targetOf(action: RowAction): string {
  return action.to === undefined ? (action.href ?? "#") : router.resolve(action.to).href;
}

// A plain link, not RouterLink, so that the router does not mark it as the current page; a click with a modifier or
// another button is left to the browser (a new tab).
function follow(event: MouseEvent, action: RowAction) {
  if (action.to === undefined || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
    return;
  }
  event.preventDefault();
  void router.push(action.to);
}

const labelOf = (action: RowAction) => `${action.label}: ${props.name}`;
</script>

<template>
  <TooltipProvider v-if="actions.length > 0">
    <div class="flex flex-wrap items-center justify-end gap-2">
      <Tooltip
        v-for="action in actions"
        :key="action.key"
      >
        <TooltipTrigger as-child>
          <button
            v-if="action.to === undefined && action.href === undefined"
            type="button"
            :aria-label="labelOf(action)"
            :data-testid="action.testid ?? `action-${action.key}`"
            :class="buttonVariants({ variant: 'outline', size: 'icon-sm' })"
            @click="emit('select', action.key, $event.currentTarget as HTMLElement)"
          >
            <component
              :is="action.icon"
              aria-hidden="true"
            />
          </button>
          <a
            v-else
            :href="targetOf(action)"
            :aria-label="action.external ? labelOf(action) + NEW_TAB : labelOf(action)"
            :target="action.external ? '_blank' : undefined"
            :rel="action.external ? 'noopener' : undefined"
            :data-testid="action.testid ?? `action-${action.key}`"
            :class="buttonVariants({ variant: 'outline', size: 'icon-sm' })"
            @click="follow($event, action)"
          >
            <component
              :is="action.icon"
              aria-hidden="true"
            />
          </a>
        </TooltipTrigger>
        <TooltipContent>
          {{ labelOf(action) }}
        </TooltipContent>
      </Tooltip>
    </div>
  </TooltipProvider>
</template>
