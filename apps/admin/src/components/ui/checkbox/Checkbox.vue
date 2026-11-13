<script setup lang="ts">
import type { CheckboxRootEmits, CheckboxRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Check } from "lucide-vue-next"
import { reactiveOmit } from "@vueuse/core"
import { cn } from "@/lib/utils"
import { CheckboxIndicator, CheckboxRoot, useForwardPropsEmits } from "reka-ui"

const props = defineProps<CheckboxRootProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<CheckboxRootEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <CheckboxRoot
    v-slot="slotProps"
    data-slot="checkbox"
    v-bind="forwarded"
    :class="
      cn('peer border-input bg-card data-[state=checked]:bg-interface data-[state=checked]:text-interface-foreground data-[state=checked]:border-interface data-[state=indeterminate]:bg-interface data-[state=indeterminate]:text-interface-foreground data-[state=indeterminate]:border-interface aria-invalid:border-destructive size-4 shrink-0 rounded-sm border transition-colors disabled:cursor-not-allowed disabled:opacity-50',
         props.class)"
  >
    <CheckboxIndicator
      data-slot="checkbox-indicator"
      class="grid place-content-center text-current transition-none"
    >
      <slot v-bind="slotProps">
        <Check class="size-3.5" />
      </slot>
    </CheckboxIndicator>
  </CheckboxRoot>
</template>
