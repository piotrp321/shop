<script setup lang="ts">
import { computed, useSlots } from "vue";
import { Label } from "@/components/ui/label";

/**
 * A field of a form (docs/features/admin-design/spec.md, „Standard formularza i okien”): the label above the control
 * (`for` = `controlId`), the hint and the errors under it, tied to the control by `aria-describedby`, and `aria-invalid`
 * for a control with an error. The control is the default slot, which gets `describedBy` and `invalid` to put on it;
 * a control that draws its own label (`CategoryCombobox`) leaves `label` out. The hint is the `hint` slot. Every error
 * is a paragraph with `data-form-error`, the mark of what the form moves the focus to after a failed save.
 */
const props = defineProps<{
  controlId: string;
  label?: string;
  errors?: string[];
  /** The `data-testid` of every error message. */
  errorTestid?: string;
}>();

const slots = useSlots();
const hintId = computed(() => `${props.controlId}-hint`);
const errorId = (index: number) => `${props.controlId}-error-${index}`;
const invalid = computed(() => (props.errors?.length ?? 0) > 0);
const describedBy = computed(() => {
  const ids = [...(slots.hint ? [hintId.value] : []), ...(props.errors ?? []).map((_, index) => errorId(index))];
  return ids.length > 0 ? ids.join(" ") : undefined;
});
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <Label
      v-if="label"
      :for="controlId"
    >
      {{ label }}
    </Label>
    <slot
      :described-by="describedBy"
      :invalid="invalid"
    />
    <p
      v-if="$slots.hint"
      :id="hintId"
      class="text-sm text-muted-foreground"
    >
      <slot name="hint" />
    </p>
    <p
      v-for="(title, index) in errors ?? []"
      :id="errorId(index)"
      :key="title"
      :data-testid="errorTestid"
      data-form-error
      class="text-sm text-status-danger"
    >
      {{ title }}
    </p>
  </div>
</template>
