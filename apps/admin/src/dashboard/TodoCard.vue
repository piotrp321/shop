<script setup lang="ts">
import { ArrowRight } from "lucide-vue-next";
import { RouterLink } from "vue-router";
import { Card } from "@/components/ui/card";
import type { TodoItem } from "./todo";

defineProps<{ items: TodoItem[] }>();

const CIRCLES = {
  blue: "bg-kpi-blue-soft text-kpi-blue",
  violet: "bg-kpi-violet-soft text-kpi-violet",
  green: "bg-kpi-green-soft text-kpi-green",
  teal: "bg-kpi-teal-soft text-kpi-teal",
  amber: "bg-kpi-amber-soft text-kpi-amber",
};
</script>

<template>
  <Card
    data-testid="todo"
    class="gap-4 p-5"
  >
    <h2 class="text-base font-bold text-heading">
      Do obsługi
    </h2>
    <ul class="flex flex-col">
      <li
        v-for="item in items"
        :key="item.id"
        class="border-b border-separator last:border-b-0"
      >
        <RouterLink
          :to="item.to"
          data-testid="todo-item"
          class="group flex items-center gap-4 rounded-md py-3 hover:bg-accent"
        >
          <span :class="['flex size-11.5 shrink-0 items-center justify-center rounded-full', CIRCLES[item.tone]]">
            <component
              :is="item.icon"
              class="size-6"
              aria-hidden="true"
            />
          </span>
          <span class="min-w-0 flex-1">
            <span
              data-testid="todo-label"
              class="block font-bold text-heading"
            >{{ item.label }}</span>
            <span
              data-testid="todo-description"
              class="block text-sm text-muted-foreground"
            >{{ item.description }}</span>
          </span>
          <ArrowRight
            class="size-5 shrink-0 text-muted-foreground group-hover:text-foreground"
            aria-hidden="true"
          />
        </RouterLink>
      </li>
    </ul>
  </Card>
</template>
