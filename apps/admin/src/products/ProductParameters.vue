<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-vue-next";
import { nextTick, ref, useId } from "vue";
import FormSection from "@/components/FormSection.vue";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { focusAfterAction } from "@/lib/focus";
import { MAX_PARAMETERS, type ParameterRow } from "./useProductForm";

const props = defineProps<{
  parameters: ParameterRow[];
  readOnly: boolean;
  /** Whether another parameter can be added (the form is editable and the limit is not reached). */
  canAdd: boolean;
  /** Messages about the list as a whole, e.g. too many parameters. */
  errors: string[];
  /** Messages for a path and everything under it. */
  errorsAt: (path: string) => string[];
  /** Messages for exactly this path. */
  errorsExactly: (path: string) => string[];
}>();

const emit = defineEmits<{
  add: [];
  remove: [index: number];
  move: [index: number, direction: -1 | 1];
  set: [index: number, field: "name" | "value", value: string];
}>();

const idPrefix = useId();
const section = ref<InstanceType<typeof FormSection> | null>(null);
const list = ref<HTMLElement | null>(null);
const addButton = ref<InstanceType<typeof Button> | null>(null);

const nameInputs = () => [...(list.value?.querySelectorAll<HTMLElement>("[data-testid=parameter-name]") ?? [])];

// The messages of a row: the ones about the row itself and the ones at its name and its value, each tied to its field.
const messageId = (index: number, part: "row" | "name" | "value", position: number) => `${idPrefix}-${part}-${index}-${position}`;
const rowMessages = (index: number) => props.errorsExactly(`parameters[${index}]`);
const nameMessages = (index: number) => props.errorsExactly(`parameters[${index}].name`);
const valueMessages = (index: number) => props.errorsExactly(`parameters[${index}].value`);
const describedBy = (index: number, part: "name" | "value") =>
  [
    ...rowMessages(index).map((_, position) => messageId(index, "row", position)),
    ...(part === "name" ? nameMessages(index) : valueMessages(index)).map((_, position) => messageId(index, part, position)),
  ].join(" ") || undefined;
const invalid = (index: number, part: "name" | "value") => (describedBy(index, part) === undefined ? undefined : "true");

// A new row takes the focus on its name: the staff member types the name next.
async function add(): Promise<void> {
  emit("add");
  await nextTick();
  nameInputs().at(-1)?.focus();
}

// The button of the removed row goes away with it: the focus goes to the name of the row that took its place and, after the
// last one, to „Dodaj parametr”, never to the body.
async function remove(index: number): Promise<void> {
  emit("remove", index);
  await nextTick();
  const next = nameInputs()[index];
  focusAfterAction(next ?? (addButton.value?.$el as HTMLElement | undefined) ?? null, section.value?.heading ?? null);
}

// The buttons stay with the row that moved (its number is the new one): the focus follows it, on the same arrow, or on the
// other one when the row reached an end and that arrow is off.
async function move(index: number, direction: -1 | 1): Promise<void> {
  emit("move", index, direction);
  await nextTick();
  const rows = [...(list.value?.querySelectorAll<HTMLElement>("[data-testid=parameter-row]") ?? [])];
  const row = rows[index + direction];
  const same = row?.querySelector<HTMLElement>(direction === -1 ? "[data-testid=parameter-up]" : "[data-testid=parameter-down]") ?? null;
  const other = row?.querySelector<HTMLElement>(direction === -1 ? "[data-testid=parameter-down]" : "[data-testid=parameter-up]") ?? null;
  focusAfterAction(same !== null && !same.hasAttribute("disabled") ? same : other, section.value?.heading ?? null);
}
</script>

<template>
  <FormSection
    ref="section"
    data-testid="parameters"
    title="Parametry"
  >
    <p class="text-sm text-muted-foreground">
      Nazwa i wartość, np. „Waga: 2,4 kg”. Kolejność jest taka, jak w sklepie. Najwyżej {{ MAX_PARAMETERS }} parametrów.
    </p>

    <Alert
      v-for="title in errors"
      :key="title"
      data-testid="parameters-error"
      data-form-error
      variant="destructive"
    >
      {{ title }}
    </Alert>

    <ul
      ref="list"
      class="flex flex-col gap-4"
    >
      <li
        v-for="(parameter, index) in parameters"
        :key="index"
        data-testid="parameter-row"
        class="flex flex-wrap items-start gap-2"
      >
        <div class="flex min-w-0 flex-1 basis-40 flex-col gap-1">
          <Input
            data-testid="parameter-name"
            :model-value="parameter.name"
            :readonly="readOnly"
            :aria-label="`Nazwa parametru ${index + 1}`"
            :aria-invalid="invalid(index, 'name')"
            :aria-describedby="describedBy(index, 'name')"
            placeholder="Nazwa"
            maxlength="60"
            @update:model-value="emit('set', index, 'name', String($event ?? ''))"
          />
          <p
            v-for="(title, position) in rowMessages(index)"
            :id="messageId(index, 'row', position)"
            :key="`row-${title}`"
            data-testid="parameter-error"
            data-form-error
            class="text-sm text-status-danger"
          >
            {{ title }}
          </p>
          <p
            v-for="(title, position) in nameMessages(index)"
            :id="messageId(index, 'name', position)"
            :key="`name-${title}`"
            data-testid="parameter-error"
            data-form-error
            class="text-sm text-status-danger"
          >
            {{ title }}
          </p>
        </div>
        <div class="flex min-w-0 flex-1 basis-40 flex-col gap-1">
          <Input
            data-testid="parameter-value"
            :model-value="parameter.value"
            :readonly="readOnly"
            :aria-label="`Wartość parametru ${index + 1}`"
            :aria-invalid="invalid(index, 'value')"
            :aria-describedby="describedBy(index, 'value')"
            placeholder="Wartość"
            maxlength="200"
            @update:model-value="emit('set', index, 'value', String($event ?? ''))"
          />
          <p
            v-for="(title, position) in valueMessages(index)"
            :id="messageId(index, 'value', position)"
            :key="`value-${title}`"
            data-testid="parameter-error"
            data-form-error
            class="text-sm text-status-danger"
          >
            {{ title }}
          </p>
        </div>
        <div
          v-if="!readOnly"
          class="flex items-center gap-1"
        >
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            class="border-0 bg-transparent"
            data-testid="parameter-up"
            :disabled="index === 0"
            :aria-label="`Przesuń parametr ${index + 1} w górę`"
            @click="move(index, -1)"
          >
            <ArrowUp aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            class="border-0 bg-transparent"
            data-testid="parameter-down"
            :disabled="index === parameters.length - 1"
            :aria-label="`Przesuń parametr ${index + 1} w dół`"
            @click="move(index, 1)"
          >
            <ArrowDown aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            class="border-0 bg-transparent text-destructive"
            data-testid="parameter-remove"
            :aria-label="`Usuń parametr ${index + 1}`"
            @click="remove(index)"
          >
            <Trash2 aria-hidden="true" />
          </Button>
        </div>
      </li>
    </ul>
    <p
      v-if="parameters.length === 0"
      data-testid="no-parameters"
      class="text-sm text-muted-foreground"
    >
      Produkt nie ma jeszcze parametrów.
    </p>

    <div
      v-if="!readOnly"
      class="flex flex-wrap items-center gap-x-3 gap-y-1"
    >
      <Button
        ref="addButton"
        type="button"
        variant="outline"
        data-testid="add-parameter"
        :disabled="!canAdd"
        @click="add"
      >
        <Plus aria-hidden="true" />
        Dodaj parametr
      </Button>
      <p
        v-if="parameters.length >= MAX_PARAMETERS"
        class="text-sm text-muted-foreground"
      >
        Osiągnięto limit {{ MAX_PARAMETERS }} parametrów.
      </p>
    </div>
  </FormSection>
</template>
