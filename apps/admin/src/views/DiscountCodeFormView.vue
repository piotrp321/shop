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
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { useUnsavedChangesGuard } from "@/composables/useUnsavedChangesGuard";
import { statusLabel, statusVariant } from "@/discount-codes/format";
import { canManageDiscountCodes } from "@/discount-codes/permissions";
import { useDiscountCodeForm } from "@/discount-codes/useDiscountCodeForm";
import { useAuthStore } from "@/stores/auth";

// What a failed save moves the focus to: the first error mark or field marked invalid, in the order of the document;
// a disabled field cannot take focus.
const FIRST_ERROR = '[data-form-error], [aria-invalid="true"]:not(:disabled)';
// A field the staff member types in: a save that ends while the focus is in one leaves it there (#250 F16).
const EDITABLE_FIELD = 'input:not([type="file"]), textarea, select, [role="combobox"]';
const FOCUSABLE = "input, textarea, select, button, a[href], [tabindex]";

const route = useRoute();
const router = useRouter();
// The route of an existing record carries its id: a failed or pending load must not look like a new record.
const isExisting = computed(() => typeof route.params.id === "string");
const auth = useAuthStore();

const {
  form: data,
  view,
  loaded,
  loadError,
  busy,
  dirty,
  readOnly,
  codeLocked,
  termsLocked,
  limitLocked,
  canSave,
  message,
  error,
  errorsAt,
  save,
  discard,
} = useDiscountCodeForm({
  discountCodeId: () => (typeof route.params.id === "string" ? route.params.id : null),
  // Only hides the actions: the API checks the permission. The roles come from /api/v1/admin/me.
  canManage: () => canManageDiscountCodes(auth.staff),
  onCreated: (id) => {
    void router.replace({ name: "discount-code", params: { id } });
  },
});

const ids = { code: useId(), kind: useId(), kindLabel: useId(), value: useId(), minimum: useId(), starts: useId(), ends: useId(), limit: useId(), enabled: useId() };

const termsDisabled = computed(() => readOnly.value || termsLocked.value);
const limitDisabled = computed(() => readOnly.value || limitLocked.value);
const valueLabel = computed(() => (data.value.kind === "percent" ? "Rabat (%)" : "Kwota rabatu (zł)"));

const root = ref<HTMLElement | null>(null);
const header = ref<InstanceType<typeof PageHeader> | null>(null);

// The result of a save: a success says so in a toast and moves the focus to the H1, because the save bar goes away with
// its button (also after the route moved from the "new" page to the page of the created code, as the same instance
// stays), unless the staff member moved to a field while the request ran: then it stays there. A failure moves the focus
// to the first field with an error, else to the message of the form, and brings it into view.
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
    const active = document.activeElement;
    if (active instanceof HTMLElement && root.value?.contains(active) && active.matches(EDITABLE_FIELD)) {
      return;
    }
    header.value?.focus();
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
    return "Nie udało się wczytać kodu rabatowego";
  }
  return view.value ? view.value.code : isExisting.value ? "Kod rabatowy" : "Nowy kod rabatowy";
});
const crumbs = computed(() => [
  { label: "Marketing" },
  { label: "Kody rabatowe", to: { name: "discount-codes" } },
  { label: loadError.value ? "Kod rabatowy" : title.value },
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
            :to="{ name: 'discount-codes' }"
          >
            Wróć do listy kodów rabatowych
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
        v-if="readOnly"
        data-testid="read-only-note"
      >
        <AlertDescription>Nie masz uprawnień do zmiany kodów rabatowych. Widok jest tylko do odczytu.</AlertDescription>
      </Alert>
      <Alert
        v-else-if="termsLocked"
        data-testid="terms-locked-note"
      >
        <AlertDescription>Kod już obowiązuje: rodzaj, wartość, minimum i początek są zablokowane. Możesz zmienić koniec, „Aktywny” i limit.</AlertDescription>
      </Alert>
      <Alert
        v-if="error"
        data-testid="form-error"
        data-form-error
        variant="destructive"
        :class="ERROR_ALERT"
      >
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <!-- From 1280 px two columns: the data on the left, the side column of 320 px on the right. Below that one column
           with the side card above the data, which is the order of the source. -->
      <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
        <div class="flex flex-col gap-6 xl:col-start-2 xl:row-start-1">
          <FormSection title="Status">
            <div
              v-if="view"
              class="flex flex-wrap items-center gap-2"
            >
              <Badge
                data-testid="code-status"
                :variant="statusVariant(view.status)"
              >
                {{ statusLabel(view.status) }}
              </Badge>
            </div>
            <div class="flex items-center gap-3">
              <Switch
                :id="ids.enabled"
                v-model="data.enabled"
                data-testid="enabled"
                :disabled="readOnly"
              />
              <Label :for="ids.enabled">
                Aktywny
              </Label>
            </div>
            <p
              v-if="view"
              class="text-sm"
            >
              Aktywne użycia: <strong data-testid="active-uses">{{ view.activeUses }}</strong>
            </p>
          </FormSection>
        </div>

        <div class="flex min-w-0 flex-col gap-6 xl:col-start-1 xl:row-start-1">
          <FormSection title="Kod i rabat">
            <FormField
              :control-id="ids.code"
              label="Kod"
              :errors="errorsAt('code')"
              error-testid="code-error"
            >
              <template #default="{ describedBy, invalid }">
                <Input
                  :id="ids.code"
                  v-model="data.code"
                  data-testid="code-input"
                  maxlength="32"
                  autocomplete="off"
                  autocapitalize="characters"
                  :disabled="readOnly || codeLocked"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
              <template #hint>
                3–32 znaki: litery, cyfry i myślnik; wielkość liter nie ma znaczenia. Kodu nie można później zmienić.
              </template>
            </FormField>

            <div class="flex flex-col gap-1.5">
              <span
                :id="ids.kindLabel"
                class="text-sm leading-none font-medium"
              >Rodzaj rabatu</span>
              <RadioGroup
                v-model="data.kind"
                data-testid="kind-input"
                class="flex flex-wrap gap-x-6 gap-y-2"
                orientation="horizontal"
                :aria-labelledby="ids.kindLabel"
                :disabled="termsDisabled"
              >
                <div
                  v-for="option in [
                    { value: 'percent', label: 'Procent' },
                    { value: 'amount', label: 'Kwota' },
                  ]"
                  :key="option.value"
                  class="flex items-center gap-2"
                >
                  <RadioGroupItem
                    :id="`${ids.kind}-${option.value}`"
                    :value="option.value"
                    :data-testid="`kind-${option.value}`"
                  />
                  <Label :for="`${ids.kind}-${option.value}`">
                    {{ option.label }}
                  </Label>
                </div>
              </RadioGroup>
            </div>

            <FormField
              :control-id="ids.value"
              :label="valueLabel"
              :errors="errorsAt('value')"
              error-testid="value-error"
            >
              <template #default="{ describedBy, invalid }">
                <Input
                  :id="ids.value"
                  v-model="data.value"
                  data-testid="value-input"
                  inputmode="decimal"
                  autocomplete="off"
                  :disabled="termsDisabled"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
            </FormField>

            <FormField
              :control-id="ids.minimum"
              label="Minimalna wartość zamówienia (zł)"
              :errors="errorsAt('minimumOrderGross')"
              error-testid="minimum-error"
            >
              <template #default="{ describedBy, invalid }">
                <Input
                  :id="ids.minimum"
                  v-model="data.minimum"
                  data-testid="minimum-input"
                  inputmode="decimal"
                  autocomplete="off"
                  :disabled="termsDisabled"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
              <template #hint>
                Puste: bez minimum. Kod kwotowy wymaga minimum większego od kwoty rabatu.
              </template>
            </FormField>
          </FormSection>

          <FormSection title="Okres i limit">
            <FormField
              :control-id="ids.starts"
              label="Początek"
              :errors="errorsAt('startsAt')"
              error-testid="starts-error"
            >
              <template #default="{ describedBy, invalid }">
                <Input
                  :id="ids.starts"
                  v-model="data.startsAt"
                  data-testid="starts-input"
                  type="datetime-local"
                  :disabled="termsDisabled"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
            </FormField>

            <FormField
              :control-id="ids.ends"
              label="Koniec"
              :errors="errorsAt('endsAt')"
              error-testid="ends-error"
            >
              <template #default="{ describedBy, invalid }">
                <Input
                  :id="ids.ends"
                  v-model="data.endsAt"
                  data-testid="ends-input"
                  type="datetime-local"
                  :disabled="readOnly"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
              <template #hint>
                Puste: bez końca.
              </template>
            </FormField>
            <p class="text-sm text-muted-foreground">
              Czas w strefie Europe/Warsaw (czas polski).
            </p>

            <FormField
              :control-id="ids.limit"
              label="Limit użyć"
              :errors="errorsAt('usageLimit')"
              error-testid="limit-error"
            >
              <template #default="{ describedBy, invalid }">
                <Input
                  :id="ids.limit"
                  v-model="data.usageLimit"
                  data-testid="limit-input"
                  inputmode="numeric"
                  autocomplete="off"
                  :disabled="limitDisabled"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
              <template #hint>
                Puste: bez limitu.
                <span
                  v-if="limitLocked"
                  data-testid="limit-locked-note"
                >Limitu nie można dodać kodowi, który już obowiązuje.</span>
              </template>
            </FormField>
          </FormSection>
        </div>
      </div>

      <SaveBar
        v-if="!readOnly && (dirty || !isExisting)"
        :creating="!isExisting"
        new-label="Nowy kod rabatowy"
        :busy="busy"
        :save-disabled="!canSave"
        @save="submit"
        @discard="discardChanges"
        @cancel="router.push({ name: 'discount-codes' })"
      />
    </template>

    <UnsavedChangesDialog
      v-model:open="leaveOpen"
      :fallback-focus="header?.heading ?? null"
      @leave="leave"
    />
  </div>
</template>
