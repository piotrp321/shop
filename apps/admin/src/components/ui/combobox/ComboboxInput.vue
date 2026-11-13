<script setup lang="ts">
import type { ComboboxInputEmits, ComboboxInputProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { SearchIcon } from "lucide-vue-next"
import { reactiveOmit } from "@vueuse/core"
import { cn } from "@/lib/utils"
import { ComboboxInput, useForwardPropsEmits } from "reka-ui"

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<ComboboxInputProps & {
  class?: HTMLAttributes["class"]
}>()

const emits = defineEmits<ComboboxInputEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <div
    data-slot="command-input-wrapper"
    class="flex h-10 items-center gap-2 rounded-md px-3 has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-ring"
  >
    <SearchIcon
      class="size-4 shrink-0 text-muted-foreground"
      aria-hidden="true"
    />
    <ComboboxInput
      data-slot="command-input"
      :class="cn(
        'placeholder:text-muted-foreground flex h-10 w-full rounded-md bg-transparent py-3 text-sm text-foreground focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
        props.class,
      )"
      v-bind="{ ...$attrs, ...forwarded }"
    >
      <slot />
    </ComboboxInput>
  </div>
</template>
