<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-vue-next";
import { computed, ref, useId } from "vue";
import ConfirmDialog from "@/components/ConfirmDialog.vue";
import FormSection from "@/components/FormSection.vue";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
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
const CONSEQUENCE = "Cena i stan wariantu przestaną być widoczne w panelu.";

const section = ref<InstanceType<typeof FormSection> | null>(null);
const idPrefix = useId();

/** What waits for the confirmation: removing a row of an existing variant, or the last axis that drops existing variants. */
const pending = ref<{ kind: "row" | "axis"; index: number; title: string; confirmLabel: string } | null>(null);
// Kept apart from `pending`, so that the text stays while the dialog closes.
const confirmOpen = ref(false);

const noAxes = computed(() => props.axes.length === 0);
const axisLabel = (position: number) => props.axes[position] || `Oś ${position + 1}`;
/** The name of a variant is its values: „czarny / 42”. */
const variantName = (index: number) => props.rows[index]?.values.filter((value) => value.trim() !== "").join(" / ") || String(index + 1);

function removeRow(index: number): void {
  if (props.rows[index]?.variantId != null) {
    const name = variantName(index);
    pending.value = { kind: "row", index, title: `Usunąć wariant ${name}?`, confirmLabel: `Usuń wariant ${name}` };
    confirmOpen.value = true;
  } else {
    emit("removeVariant", index);
  }
}

function removeAxis(index: number): void {
  // Without axes only the first row stays, so the other existing variants are removed with the last axis.
  const dropsExisting = props.axes.length === 1 && props.rows.slice(1).some((row) => row.variantId != null);
  if (dropsExisting) {
    pending.value = { kind: "axis", index, title: `Usunąć oś ${axisLabel(index)}?`, confirmLabel: `Usuń oś ${axisLabel(index)}` };
    confirmOpen.value = true;
  } else {
    emit("removeAxis", index);
  }
}

function confirm(): void {
  const current = pending.value;
  if (current?.kind === "row") {
    emit("removeVariant", current.index);
  } else if (current?.kind === "axis") {
    emit("removeAxis", current.index);
  }
}

const rowErrors = (index: number) => [...props.errorsExactly(`variants[${index}]`), ...props.errorsExactly(`variants[${index}].values`), ...props.errorsAt(`variants[${index}].variantId`)];
const ids = (kind: string, ...parts: number[]) => `${idPrefix}-${kind}-${parts.join("-")}`;
</script>

<template>
  <FormSection
    ref="section"
    data-testid="variants"
    title="Warianty"
  >
    <Alert
      v-for="title in errors"
      :key="title"
      data-testid="variants-error"
      data-form-error
      variant="destructive"
    >
      {{ title }}
    </Alert>

    <div class="flex flex-col gap-2">
      <div
        v-for="(axis, position) in axes"
        :key="position"
        class="flex flex-col gap-1"
      >
        <div class="flex items-center gap-1">
          <Input
            data-testid="axis-name"
            class="max-w-xs"
            :model-value="axis"
            :disabled="readOnly"
            :aria-invalid="errorsAt(`variantAxes[${position}]`).length > 0 ? 'true' : undefined"
            :aria-describedby="errorsAt(`variantAxes[${position}]`).length > 0 ? ids('axis-error', position) : undefined"
            :aria-label="`Nazwa osi ${position + 1}`"
            placeholder="Nazwa osi, np. Kolor"
            maxlength="30"
            @update:model-value="emit('setAxis', position, String($event ?? ''))"
          />
          <template v-if="!readOnly">
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              class="border-0 bg-transparent"
              data-testid="axis-move-up"
              :disabled="axesLocked || position === 0"
              :aria-label="`Przesuń oś ${position + 1} wcześniej`"
              @click="emit('moveAxis', position, -1)"
            >
              <ArrowUp aria-hidden="true" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              class="border-0 bg-transparent"
              data-testid="axis-move-down"
              :disabled="axesLocked || position === axes.length - 1"
              :aria-label="`Przesuń oś ${position + 1} później`"
              @click="emit('moveAxis', position, 1)"
            >
              <ArrowDown aria-hidden="true" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              class="border-0 bg-transparent text-destructive"
              data-testid="axis-remove"
              :disabled="axesLocked"
              :aria-label="`Usuń oś ${position + 1}`"
              @click="removeAxis(position)"
            >
              <Trash2 aria-hidden="true" />
            </Button>
          </template>
        </div>
        <p
          v-for="title in errorsAt(`variantAxes[${position}]`)"
          :id="ids('axis-error', position)"
          :key="title"
          data-testid="axis-error"
          data-form-error
          class="text-sm text-status-danger"
        >
          {{ title }}
        </p>
      </div>
      <div
        v-if="!readOnly"
        class="flex flex-wrap items-center gap-x-3 gap-y-1"
      >
        <Button
          type="button"
          variant="outline"
          data-testid="add-axis"
          :disabled="axesLocked || axes.length >= MAX_AXES"
          @click="emit('addAxis')"
        >
          <Plus aria-hidden="true" />
          Dodaj oś
        </Button>
        <p
          v-if="!axesLocked && axes.length >= MAX_AXES"
          class="text-sm text-muted-foreground"
        >
          Produkt może mieć najwyżej {{ MAX_AXES }} osie.
        </p>
      </div>
    </div>

    <Table
      role="region"
      aria-label="Warianty produktu"
      tabindex="0"
    >
      <TableHeader v-if="!noAxes">
        <TableRow>
          <TableHead
            v-for="(_, position) in axes"
            :key="position"
            scope="col"
          >
            {{ axisLabel(position) }}
          </TableHead>
          <TableHead scope="col">
            <span class="sr-only">Akcje</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow
          v-for="(row, index) in rows"
          :key="row.variantId ?? `new-${index}`"
          data-testid="variant-row"
        >
          <TableCell v-if="noAxes">
            Wariant domyślny
          </TableCell>
          <TableCell
            v-for="(value, position) in row.values"
            :key="position"
            class="align-top"
          >
            <Input
              data-testid="variant-value"
              class="min-w-32"
              :model-value="value"
              :disabled="readOnly"
              :aria-invalid="errorsAt(`variants[${index}].values[${position}]`).length > 0 ? 'true' : undefined"
              :aria-describedby="errorsAt(`variants[${index}].values[${position}]`).length > 0 ? ids('value-error', index, position) : undefined"
              :aria-label="`${axisLabel(position)}, wariant ${index + 1}`"
              maxlength="40"
              @update:model-value="emit('setValue', index, position, String($event ?? ''))"
            />
            <p
              v-for="title in errorsAt(`variants[${index}].values[${position}]`)"
              :id="ids('value-error', index, position)"
              :key="title"
              data-testid="value-error"
              data-form-error
              class="mt-1 text-sm whitespace-normal text-status-danger"
            >
              {{ title }}
            </p>
          </TableCell>
          <TableCell class="align-top">
            <Button
              v-if="!readOnly && !noAxes"
              type="button"
              variant="ghost"
              size="icon-sm"
              class="border-0 bg-transparent text-destructive"
              data-testid="variant-remove"
              :disabled="rows.length <= 1"
              :aria-label="`Usuń wariant ${index + 1}`"
              @click="removeRow(index)"
            >
              <Trash2 aria-hidden="true" />
            </Button>
            <p
              v-for="title in rowErrors(index)"
              :key="title"
              data-testid="row-error"
              data-form-error
              class="text-sm whitespace-normal text-status-danger"
            >
              {{ title }}
            </p>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>

    <div
      v-if="!readOnly"
      class="flex flex-wrap items-center gap-x-3 gap-y-1"
    >
      <Button
        type="button"
        variant="outline"
        data-testid="add-variant"
        :disabled="noAxes || rows.length >= MAX_VARIANTS"
        @click="emit('addVariant')"
      >
        <Plus aria-hidden="true" />
        Dodaj wariant
      </Button>
      <p
        v-if="noAxes && !axesLocked"
        class="text-sm text-muted-foreground"
      >
        Dodaj oś, aby produkt mógł mieć więcej niż jeden wariant.
      </p>
      <p
        v-else-if="!noAxes && rows.length >= MAX_VARIANTS"
        class="text-sm text-muted-foreground"
      >
        Osiągnięto limit {{ MAX_VARIANTS }} wariantów.
      </p>
    </div>

    <p
      v-if="axesLocked && !readOnly"
      data-testid="variants-locked"
      class="text-sm text-muted-foreground"
    >
      {{ LOCKED_EXPLANATION }}
    </p>

    <ConfirmDialog
      v-model:open="confirmOpen"
      :title="pending?.title ?? ''"
      :description="CONSEQUENCE"
      :confirm-label="pending?.confirmLabel ?? ''"
      destructive
      :fallback-focus="section?.heading ?? null"
      @confirm="confirm"
    />
  </FormSection>
</template>
