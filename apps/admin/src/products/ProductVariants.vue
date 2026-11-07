<script setup lang="ts">
import Button from "primevue/button";
import Dialog from "primevue/dialog";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import { computed, ref } from "vue";
import { MAX_AXES, MAX_VARIANTS, type VariantRow } from "./useProductForm";

const props = defineProps<{
  axes: string[];
  rows: VariantRow[];
  /** Messages about the variants as a whole, e.g. a 409 from the API. */
  errors: string[];
  readOnly: boolean;
  /** After the first publication the axes cannot be added, removed or moved. */
  axesLocked: boolean;
  /** Messages for a path and everything under it. */
  errorsAt: (path: string) => string[];
  /** Messages for exactly this path. */
  errorsExactly: (path: string) => string[];
}>();

const emit = defineEmits<{
  addAxis: [];
  setAxis: [index: number, name: string];
  removeAxis: [index: number];
  moveAxis: [index: number, direction: -1 | 1];
  addVariant: [];
  removeVariant: [index: number];
  setValue: [row: number, position: number, value: string];
}>();

const LOCKED_EXPLANATION =
  "Wartości istniejących wariantów można poprawić tylko w wielkości liter. Inną zmianę zrób przez dodanie nowego wariantu i usunięcie starego.";

/** What waits for the confirmation: removing a row of an existing variant, or the last axis that drops existing variants. */
const pending = ref<{ kind: "row" | "axis"; index: number } | null>(null);
const confirmVisible = computed({
  get: () => pending.value !== null,
  set: (visible: boolean) => {
    if (!visible) {
      pending.value = null;
    }
  },
});

const noAxes = computed(() => props.axes.length === 0);
const axisLabel = (position: number) => props.axes[position] || `Oś ${position + 1}`;

function removeRow(index: number): void {
  if (props.rows[index]?.variantId != null) {
    pending.value = { kind: "row", index };
  } else {
    emit("removeVariant", index);
  }
}

function removeAxis(index: number): void {
  // Without axes only the first row stays, so the other existing variants are removed with the last axis.
  const dropsExisting = props.axes.length === 1 && props.rows.slice(1).some((row) => row.variantId != null);
  if (dropsExisting) {
    pending.value = { kind: "axis", index };
  } else {
    emit("removeAxis", index);
  }
}

function confirm(): void {
  const current = pending.value;
  pending.value = null;
  if (current?.kind === "row") {
    emit("removeVariant", current.index);
  } else if (current?.kind === "axis") {
    emit("removeAxis", current.index);
  }
}

const rowErrors = (index: number) => [...props.errorsExactly(`variants[${index}]`), ...props.errorsExactly(`variants[${index}].values`), ...props.errorsAt(`variants[${index}].variantId`)];
</script>

<template>
  <section
    data-testid="variants"
    class="variants"
    aria-labelledby="variants-heading"
  >
    <h2 id="variants-heading">
      Warianty
    </h2>
    <Message
      v-for="title in errors"
      :key="title"
      data-testid="variants-error"
      severity="error"
    >
      {{ title }}
    </Message>

    <div class="axes">
      <div
        v-for="(axis, position) in axes"
        :key="position"
        class="axis"
      >
        <div class="axis-row">
          <InputText
            data-testid="axis-name"
            :model-value="axis"
            :disabled="readOnly"
            :invalid="errorsAt(`variantAxes[${position}]`).length > 0"
            :aria-label="`Nazwa osi ${position + 1}`"
            placeholder="Nazwa osi, np. Kolor"
            maxlength="30"
            @update:model-value="emit('setAxis', position, String($event ?? ''))"
          />
          <template v-if="!readOnly">
            <Button
              data-testid="axis-move-up"
              icon="pi pi-arrow-up"
              severity="secondary"
              text
              size="small"
              :disabled="axesLocked || position === 0"
              :aria-label="`Przesuń oś ${position + 1} w lewo`"
              @click="emit('moveAxis', position, -1)"
            />
            <Button
              data-testid="axis-move-down"
              icon="pi pi-arrow-down"
              severity="secondary"
              text
              size="small"
              :disabled="axesLocked || position === axes.length - 1"
              :aria-label="`Przesuń oś ${position + 1} w prawo`"
              @click="emit('moveAxis', position, 1)"
            />
            <Button
              data-testid="axis-remove"
              icon="pi pi-trash"
              severity="secondary"
              text
              size="small"
              :disabled="axesLocked"
              :aria-label="`Usuń oś ${position + 1}`"
              @click="removeAxis(position)"
            />
          </template>
        </div>
        <Message
          v-for="title in errorsAt(`variantAxes[${position}]`)"
          :key="title"
          data-testid="axis-error"
          severity="error"
          size="small"
          variant="simple"
        >
          {{ title }}
        </Message>
      </div>
      <div v-if="!readOnly">
        <Button
          data-testid="add-axis"
          label="Dodaj oś"
          icon="pi pi-plus"
          severity="secondary"
          :disabled="axesLocked || axes.length >= MAX_AXES"
          @click="emit('addAxis')"
        />
        <small
          v-if="!axesLocked && axes.length >= MAX_AXES"
          class="hint"
        > Produkt może mieć najwyżej {{ MAX_AXES }} osie.</small>
      </div>
    </div>

    <table class="table">
      <thead v-if="!noAxes">
        <tr>
          <th
            v-for="(_, position) in axes"
            :key="position"
            scope="col"
          >
            {{ axisLabel(position) }}
          </th>
          <th scope="col">
            <span class="sr-only">Akcje</span>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(row, index) in rows"
          :key="row.variantId ?? `new-${index}`"
          data-testid="variant-row"
        >
          <td v-if="noAxes">
            Wariant domyślny
          </td>
          <td
            v-for="(value, position) in row.values"
            :key="position"
          >
            <InputText
              data-testid="variant-value"
              :model-value="value"
              :disabled="readOnly"
              :invalid="errorsAt(`variants[${index}].values[${position}]`).length > 0"
              :aria-label="`${axisLabel(position)}, wariant ${index + 1}`"
              maxlength="40"
              @update:model-value="emit('setValue', index, position, String($event ?? ''))"
            />
            <Message
              v-for="title in errorsAt(`variants[${index}].values[${position}]`)"
              :key="title"
              data-testid="value-error"
              severity="error"
              size="small"
              variant="simple"
            >
              {{ title }}
            </Message>
          </td>
          <td>
            <Button
              v-if="!readOnly && !noAxes"
              data-testid="variant-remove"
              icon="pi pi-trash"
              severity="secondary"
              text
              size="small"
              :disabled="rows.length <= 1"
              :aria-label="`Usuń wariant ${index + 1}`"
              @click="removeRow(index)"
            />
            <Message
              v-for="title in rowErrors(index)"
              :key="title"
              data-testid="row-error"
              severity="error"
              size="small"
              variant="simple"
            >
              {{ title }}
            </Message>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="!readOnly">
      <Button
        data-testid="add-variant"
        label="Dodaj wariant"
        icon="pi pi-plus"
        severity="secondary"
        :disabled="noAxes || rows.length >= MAX_VARIANTS"
        @click="emit('addVariant')"
      />
      <small
        v-if="noAxes"
        class="hint"
      > Dodaj oś, aby produkt mógł mieć więcej niż jeden wariant.</small>
      <small
        v-else-if="rows.length >= MAX_VARIANTS"
        class="hint"
      > Osiągnięto limit {{ MAX_VARIANTS }} wariantów.</small>
    </div>

    <p
      v-if="axesLocked && !readOnly"
      data-testid="variants-locked"
      class="hint"
    >
      {{ LOCKED_EXPLANATION }}
    </p>

    <Dialog
      v-model:visible="confirmVisible"
      modal
      header="Usunąć wariant?"
      :style="{ width: '28rem' }"
    >
      <div
        data-testid="remove-confirm"
        class="confirm"
      >
        <p>Cena i stan wariantu przestaną być widoczne w panelu.</p>
        <div class="confirm-actions">
          <Button
            data-testid="remove-confirm-cancel"
            label="Anuluj"
            severity="secondary"
            @click="confirmVisible = false"
          />
          <Button
            data-testid="remove-confirm-accept"
            label="Usuń"
            icon="pi pi-trash"
            severity="danger"
            @click="confirm()"
          />
        </div>
      </div>
    </Dialog>
  </section>
</template>

<style scoped>
.variants {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.hint {
  color: var(--p-text-muted-color);
  margin: 0;
}

.axes {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.axis {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.axis-row {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.table {
  border-collapse: collapse;
  width: 100%;
}

.table th,
.table td {
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
  text-align: left;
  vertical-align: top;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}

.confirm {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.confirm-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>
