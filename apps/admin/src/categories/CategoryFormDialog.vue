<script setup lang="ts">
import { nextTick, ref, watch } from "vue";
import FormField from "@/components/FormField.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { focusAfterAction } from "@/lib/focus";
import type { CategoryProblem } from "./problem";
import type { CategoryInput, CategoryOutcome } from "./useCategoryScreen";

/**
 * The window of „Dodaj kategorię”, „Dodaj podkategorię” and „Edytuj”: the name and the slug, both required (the format
 * and the uniqueness of the slug are the API's; the hint under the field says the format). `submit` is the request of the
 * view; its refusal is said where it belongs: a taken slug and the violations of 422 at their fields, everything else
 * (a version conflict, a tree too deep, a category that is gone, a failed request) in an alert of the window, and the
 * focus goes to the message. A save closes the window and tells the view (`saved`), which says it in a toast.
 */
const props = defineProps<{
  title: string;
  description: string;
  submitLabel: string;
  initial: CategoryInput;
  submit: (input: CategoryInput) => Promise<CategoryOutcome>;
  /** The button that opened the window; the focus goes back to it, or to `fallbackFocus` when it is gone. */
  returnFocusTo: HTMLElement | null;
  fallbackFocus: HTMLElement | null;
}>();
const emit = defineEmits<{ saved: [outcome: Extract<CategoryOutcome, { status: "saved" }>] }>();

const open = defineModel<boolean>("open", { required: true });

const name = ref("");
const slug = ref("");
const nameErrors = ref<string[]>([]);
const slugErrors = ref<string[]>([]);
const message = ref<string | null>(null);
const saving = ref(false);
const nameInput = ref<InstanceType<typeof Input> | null>(null);
const slugInput = ref<InstanceType<typeof Input> | null>(null);
const alert = ref<InstanceType<typeof Alert> | null>(null);
// Counts the openings: an answer that comes after the window was closed or opened again is not for this opening.
let session = 0;

watch(
  open,
  (isOpen) => {
    session++;
    if (isOpen) {
      name.value = props.initial.name;
      slug.value = props.initial.slug;
      nameErrors.value = [];
      slugErrors.value = [];
      message.value = null;
      saving.value = false;
    }
  },
  { immediate: true },
);

const focusOf = (target: InstanceType<typeof Input> | InstanceType<typeof Alert> | null) => (target?.$el as HTMLElement | undefined)?.focus();

function focusNameOnOpen(event: Event) {
  event.preventDefault();
  focusOf(nameInput.value);
}

function returnFocus(event: Event) {
  event.preventDefault();
  focusAfterAction(props.returnFocusTo, props.fallbackFocus);
}

async function showProblem(problem: CategoryProblem) {
  if (problem.kind === "slug-taken") {
    slugErrors.value = [problem.message];
  } else if (problem.kind === "invalid") {
    nameErrors.value = problem.fields.name ?? [];
    slugErrors.value = problem.fields.slug ?? [];
    message.value = problem.message;
  } else {
    message.value = problem.message;
  }
  await nextTick();
  // The first message the staff member has to read: the name, then the slug, then the alert of the window.
  focusOf(nameErrors.value.length > 0 ? nameInput.value : slugErrors.value.length > 0 ? slugInput.value : alert.value);
}

async function save() {
  if (saving.value) {
    return;
  }
  const input = { name: name.value.trim(), slug: slug.value.trim() };
  nameErrors.value = input.name === "" ? ["Podaj nazwę kategorii."] : [];
  slugErrors.value = input.slug === "" ? ["Podaj slug kategorii."] : [];
  message.value = null;
  if (nameErrors.value.length > 0 || slugErrors.value.length > 0) {
    await nextTick();
    focusOf(nameErrors.value.length > 0 ? nameInput.value : slugInput.value);
    return;
  }
  saving.value = true;
  const current = session;
  const outcome = await props.submit(input);
  if (current !== session) {
    return;
  }
  saving.value = false;
  if (outcome.status === "saved") {
    open.value = false;
    emit("saved", outcome);
    return;
  }
  await showProblem(outcome.problem);
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent
      data-testid="category-dialog"
      class="max-h-[calc(100dvh-2rem)] w-[min(28rem,calc(100vw-2rem))] max-w-none overflow-y-auto sm:max-w-none"
      @open-auto-focus="focusNameOnOpen"
      @close-auto-focus="returnFocus"
    >
      <DialogHeader>
        <DialogTitle>{{ title }}</DialogTitle>
        <DialogDescription>{{ description }}</DialogDescription>
      </DialogHeader>
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="save"
      >
        <Alert
          v-if="message"
          ref="alert"
          data-testid="dialog-error"
          variant="destructive"
          tabindex="-1"
        >
          <AlertDescription>{{ message }}</AlertDescription>
        </Alert>
        <FormField
          control-id="category-name-input"
          label="Nazwa"
          :errors="nameErrors"
          error-testid="name-error"
        >
          <template #default="{ describedBy, invalid }">
            <Input
              id="category-name-input"
              ref="nameInput"
              v-model="name"
              autocomplete="off"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
              :aria-required="true"
            />
          </template>
        </FormField>
        <FormField
          control-id="category-slug-input"
          label="Slug"
          :errors="slugErrors"
          error-testid="slug-error"
        >
          <template #default="{ describedBy, invalid }">
            <Input
              id="category-slug-input"
              ref="slugInput"
              v-model="slug"
              autocomplete="off"
              autocapitalize="none"
              spellcheck="false"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
              :aria-required="true"
            />
          </template>
          <template #hint>
            Adres kategorii w sklepie: małe litery bez polskich znaków, cyfry i pojedyncze myślniki, do 60 znaków, np. namioty-turystyczne.
          </template>
        </FormField>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            data-testid="cancel-category"
            @click="open = false"
          >
            Anuluj
          </Button>
          <Button
            type="submit"
            variant="interface"
            data-testid="save-category"
            :disabled="saving"
          >
            {{ submitLabel }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
