<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner"
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "lucide-vue-next"
import { computed } from "vue"
import { Toaster as Sonner } from "vue-sonner"
import "vue-sonner/style.css"
import { cn } from "@/lib/utils"

// Polish names of the region and of the close button of a toast (vue-sonner defaults to English). vue-sonner appends the
// label of its hotkey to the name of the region ("Powiadomienia alt+T") and focuses the toasts when the hotkey is pressed.
// "Key" is a hotkey without a label (the label drops "Key") that no keyboard event can match (a code is "KeyT", not
// "Key"): the name is exactly "Powiadomienia" and there is no hotkey, which the panel does not document either.
const props = withDefaults(defineProps<ToasterProps>(), {
  containerAriaLabel: "Powiadomienia",
  hotkey: () => ["Key"],
})
const toastOptions = computed(() => ({ closeButtonAriaLabel: "Zamknij powiadomienie", ...props.toastOptions }))
</script>

<template>
  <Sonner
    :class="cn('group', props.class)"
    :style="{
      '--normal-bg': 'var(--popover)',
      '--normal-text': 'var(--popover-foreground)',
      '--normal-border': 'var(--border)',
      '--border-radius': 'var(--radius)',
    }"
    v-bind="props"
    :toast-options="toastOptions"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-4" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-4" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-4" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-4" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-4" />
    </template>
  </Sonner>
</template>
