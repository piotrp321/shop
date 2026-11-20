<script setup lang="ts">
import { computed, nextTick, ref, useId } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import FormField from "@/components/FormField.vue";
import FormSection from "@/components/FormSection.vue";
import PageHeader from "@/components/PageHeader.vue";
import SaveBar from "@/components/SaveBar.vue";
import UnsavedChangesDialog from "@/components/UnsavedChangesDialog.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { useUnsavedChangesGuard } from "@/composables/useUnsavedChangesGuard";
import { useDeliveryMethodForm } from "@/delivery/useDeliveryMethodForm";

// What a failed save moves the focus to: the first error mark or field marked invalid, in the order of the document.
const FIRST_ERROR = '[data-form-error], [aria-invalid="true"]:not(:disabled)';
const FOCUSABLE = "input, textarea, select, button, a[href], [tabindex]";

const route = useRoute();
const router = useRouter();
// The route of an existing method carries its id: a failed or pending load must not look like a new method.
const isExisting = computed(() => typeof route.params.id === "string");

const { form: data, view, loaded, loadError, busy, dirty, canSave, message, error, errorsAt, save, discard } = useDeliveryMethodForm({
  methodId: () => (typeof route.params.id === "string" ? route.params.id : null),
  onSaved: () => {
    void router.push({ name: "delivery" });
  },
});

const ids = { name: useId(), description: useId(), price: useId(), freeFrom: useId(), active: useId(), position: useId(), tracking: useId() };

const root = ref<HTMLElement | null>(null);
const header = ref<InstanceType<typeof PageHeader> | null>(null);

// A save that succeeds says so in a toast and the form goes back to the list (the page focus takes the H1 there). A save that
// fails moves the focus to the first field with an error, else to the message above the form, and brings it into view.
async function submit(): Promise<void> {
  const result = await save();
  if (result === null) {
    return;
  }
  await nextTick();
  if (result === "success") {
    if (message.value) {
      toast.success(message.value);
    }
    return;
  }
  const target = root.value?.querySelector<HTMLElement>(FIRST_ERROR);
  if (!target) {
    return;
  }
  if (!target.matches(FOCUSABLE)) {
    // A message is not focusable by itself; -1 makes it a target for the script, not a stop for the keyboard.
    target.tabIndex = -1;
  }
  target.focus({ preventScroll: true });
  target.scrollIntoView({ block: "center" });
}

// The bar goes away with „Odrzuć”, so the focus goes to the H1.
async function discardChanges(): Promise<void> {
  discard();
  await nextTick();
  header.value?.focus();
}

const { open: leaveOpen, leave } = useUnsavedChangesGuard(() => dirty.value);

const title = computed(() => {
  if (loadError.value) {
    return "Nie udało się wczytać metody dostawy";
  }
  return view.value ? view.value.name : isExisting.value ? "Metoda dostawy" : "Nowa metoda dostawy";
});
const crumbs = computed(() => [
  { label: "Sklep" },
  { label: "Dostawa", to: { name: "delivery" } },
  { label: loadError.value ? "Metoda dostawy" : title.value },
]);

// The text of an error is the colour of the status „danger” (#446): the contrast of the default one is lower on the card.
const ERROR_ALERT = "border-status-danger text-status-danger *:data-[slot=alert-description]:text-status-danger";
</script>

<template>
  <div
    ref="root"
    class="flex flex-col gap-6"
  >
    <PageHeader
      ref="header"
      :title="title"
      :crumbs="crumbs"
    />

    <Alert
      v-if="loadError"
      data-testid="load-error"
      variant="destructive"
      :class="ERROR_ALERT"
    >
      <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span>{{ loadError }}</span>
        <Button
          as-child
          variant="outline"
          size="sm"
        >
          <RouterLink
            data-testid="back-to-list"
            :to="{ name: 'delivery' }"
          >
            Wróć do listy metod dostawy
          </RouterLink>
        </Button>
      </AlertDescription>
    </Alert>
    <Skeleton
      v-else-if="!loaded"
      class="h-80 w-full"
    />

    <template v-else>
      <Alert
        v-if="error"
        data-testid="form-error"
        data-form-error
        variant="destructive"
        :class="ERROR_ALERT"
      >
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <!-- While the request runs nothing can be typed: the answer rebuilds the form from the API and would overwrite it. -->
      <fieldset
        :disabled="busy"
        class="flex max-w-3xl min-w-0 flex-col gap-6"
      >
        <FormSection title="Metoda dostawy">
          <FormField
            :control-id="ids.name"
            label="Nazwa"
            :errors="errorsAt('name')"
            error-testid="name-error"
          >
            <template #default="{ describedBy, invalid }">
              <Input
                :id="ids.name"
                v-model="data.name"
                data-testid="name-input"
                autocomplete="off"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
              />
            </template>
          </FormField>

          <FormField
            :control-id="ids.description"
            label="Opis (czas dostawy)"
            :errors="errorsAt('description')"
            error-testid="description-error"
          >
            <template #default="{ describedBy, invalid }">
              <Input
                :id="ids.description"
                v-model="data.description"
                data-testid="description-input"
                autocomplete="off"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
              />
            </template>
            <template #hint>
              Na przykład: Dostawa w 2–3 dni robocze. Klient widzi to w koszyku i w kasie.
            </template>
          </FormField>
        </FormSection>

        <FormSection title="Cena">
          <FormField
            :control-id="ids.price"
            label="Cena brutto (zł)"
            :errors="errorsAt('priceGross')"
            error-testid="price-error"
          >
            <template #default="{ describedBy, invalid }">
              <Input
                :id="ids.price"
                v-model="data.price"
                data-testid="price-input"
                inputmode="decimal"
                autocomplete="off"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
              />
            </template>
          </FormField>

          <FormField
            :control-id="ids.freeFrom"
            label="Darmowa od (zł, opcjonalnie)"
            :errors="errorsAt('freeFromGross')"
            error-testid="free-from-error"
          >
            <template #default="{ describedBy, invalid }">
              <Input
                :id="ids.freeFrom"
                v-model="data.freeFrom"
                data-testid="free-from-input"
                inputmode="decimal"
                autocomplete="off"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
              />
            </template>
            <template #hint>
              Puste: dostawa nigdy nie jest darmowa. Próg dotyczy wartości towarów po rabatach.
            </template>
          </FormField>
        </FormSection>

        <FormSection title="Dostępność">
          <FormField
            :control-id="ids.active"
            :errors="errorsAt('active')"
            error-testid="active-error"
          >
            <template #default="{ describedBy, invalid }">
              <div class="flex items-center gap-3">
                <Switch
                  :id="ids.active"
                  v-model="data.active"
                  data-testid="active"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
                <Label :for="ids.active">
                  Aktywna
                </Label>
              </div>
            </template>
            <template #hint>
              Aktywnych metod może być najwyżej 20. Wyłączona metoda znika z koszyka i kasy.
            </template>
          </FormField>

          <FormField
            :control-id="ids.position"
            label="Kolejność"
            :errors="errorsAt('position')"
            error-testid="position-error"
          >
            <template #default="{ describedBy, invalid }">
              <Input
                :id="ids.position"
                v-model="data.position"
                data-testid="position-input"
                inputmode="numeric"
                autocomplete="off"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
              />
            </template>
            <template #hint>
              Liczba od 0 do 999: metody aktywne są pokazywane od najmniejszej, a przy tej samej liczbie według nazwy.
            </template>
          </FormField>
        </FormSection>

        <FormSection title="Śledzenie przesyłki">
          <FormField
            :control-id="ids.tracking"
            label="Link śledzenia (opcjonalnie)"
            :errors="errorsAt('trackingUrlTemplate')"
            error-testid="tracking-error"
          >
            <template #default="{ describedBy, invalid }">
              <Input
                :id="ids.tracking"
                v-model="data.trackingUrlTemplate"
                data-testid="tracking-input"
                inputmode="url"
                autocomplete="off"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
              />
            </template>
            <template #hint>
              Adres https:// z {numer} w miejscu numeru przesyłki
            </template>
          </FormField>
        </FormSection>
      </fieldset>

      <SaveBar
        v-if="dirty || !isExisting"
        :creating="!isExisting"
        new-label="Nowa metoda dostawy"
        :busy="busy"
        :save-disabled="!canSave"
        @save="submit"
        @discard="discardChanges"
        @cancel="router.push({ name: 'delivery' })"
      />
    </template>

    <UnsavedChangesDialog
      v-model:open="leaveOpen"
      :fallback-focus="header?.heading ?? null"
      @leave="leave"
    />
  </div>
</template>
