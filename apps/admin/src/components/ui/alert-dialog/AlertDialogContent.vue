<script setup lang="ts">
import type { AlertDialogContentEmits, AlertDialogContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { X } from "lucide-vue-next"
import { reactiveOmit } from "@vueuse/core"
import { cn } from "@/lib/utils"
import {
  AlertDialogContent,
  AlertDialogOverlay,
  AlertDialogPortal,
  DialogClose,
  useForwardPropsEmits,
} from "reka-ui"

defineOptions({
  inheritAttrs: false,
})

// The close button closes like Escape and has the Polish name „Zamknij”. It is a DialogClose, not an AlertDialogCancel:
// the last AlertDialogCancel to register receives the initial focus, and that must be the cancel button of the content.
const props = withDefaults(defineProps<AlertDialogContentProps & { class?: HTMLAttributes["class"], showCloseButton?: boolean }>(), {
  class: undefined,
  showCloseButton: true,
})
const emits = defineEmits<AlertDialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class", "showCloseButton")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <AlertDialogPortal>
    <AlertDialogOverlay
      data-slot="alert-dialog-overlay"
      class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-overlay"
    />
    <AlertDialogContent
      data-slot="alert-dialog-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="
        cn(
          'bg-card text-card-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-lg border p-6 shadow-lg duration-200 sm:max-w-lg',
          props.class,
        )
      "
    >
      <slot />

      <DialogClose
        v-if="showCloseButton"
        data-slot="alert-dialog-close"
        class="absolute top-4 right-4 rounded-sm text-muted-foreground transition-colors hover:text-foreground [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
      >
        <X aria-hidden="true" />
        <span class="sr-only">Zamknij</span>
      </DialogClose>
    </AlertDialogContent>
  </AlertDialogPortal>
</template>
