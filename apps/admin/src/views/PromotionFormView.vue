<script setup lang="ts">
import { CircleStop, Plus, Trash2, TriangleAlert, X } from "lucide-vue-next";
import { computed, nextTick, ref, useId } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import ConfirmDialog from "@/components/ConfirmDialog.vue";
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
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useUnsavedChangesGuard } from "@/composables/useUnsavedChangesGuard";
import { formatMoney } from "@/prices/format";
import { CURRENCY, statusLabel, statusVariant } from "@/promotions/format";
import { canManagePromotions } from "@/promotions/permissions";
import PromotionVariantPicker from "@/promotions/PromotionVariantPicker.vue";
import { type ActionResult, usePromotionForm } from "@/promotions/usePromotionForm";
import { useAuthStore } from "@/stores/auth";

// What a failed save moves the focus to: the first error mark or field marked invalid, in the order of the document;
// a disabled field cannot take focus.
const FIRST_ERROR = '[data-form-error], [aria-invalid="true"]:not(:disabled)';
// A field the staff member types in: an action of the card „Status” that ends while the focus is in one leaves it there
// (#250 F16). A save cannot: the fields are off while it runs.
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
  locked,
  dirty,
  status: promotionStatus,
  readOnly,
  scheduleLocked,
  canSave,
  canEndNow,
  canDelete,
  message,
  error,
  ending,
  endNotice,
  endError,
  starting,
  startNotice,
  startError,
  errorsAt,
  isConflicting,
  save,
  endNow,
  remove,
  refresh,
  discard,
  addVariants,
  removeVariant,
} = usePromotionForm({
  promotionId: () => (typeof route.params.id === "string" ? route.params.id : null),
  // Only hides the actions: the API checks the permission. The roles come from /api/v1/admin/me.
  canManage: () => canManagePromotions(auth.staff),
  onCreated: (id) => {
    void router.replace({ name: "promotion", params: { id } });
  },
  onDeleted: () => {
    void router.push({ name: "promotions" });
  },
  // The promotion that „Zakończ teraz” left active was read as ended after the call returned (#304 F7).
  onEnded: (text) => {
    toast.success(text);
  },
});

const ids = {
  name: useId(),
  percent: useId(),
  starts: useId(),
  startNow: useId(),
  ends: useId(),
  ending: useId(),
  starting: useId(),
  reason: useId(),
};

const root = ref<HTMLElement | null>(null);
const header = ref<InstanceType<typeof PageHeader> | null>(null);
const statusCard = ref<InstanceType<typeof FormSection> | null>(null);
const opener = ref<InstanceType<typeof Button> | null>(null);
const picker = ref<InstanceType<typeof PromotionVariantPicker> | null>(null);

// The result of an action: a success says so in a toast (a save and the immediate end by the text of the form, a
// deletion by its own) and moves the focus to where the button was: the save bar goes away with its button, so the focus
// goes to the H1; ending replaces its button, so it goes to the heading of the card „Status”; after a deletion the view
// leaves and the shell takes the focus to the H1 of the list. Unless the staff member moved to a field while an action of
// the card „Status” ran: then it stays there (the fields are off while a save runs). A failure moves the focus to the first field with an error, else to the message of the form,
// and brings it into view (#304 F3).
async function run(action: () => Promise<ActionResult>, after: "page" | "status" | "left", toastText?: string): Promise<void> {
  const result = await action();
  if (result === null) {
    return;
  }
  await nextTick();
  if (result === "success") {
    const text = toastText ?? message.value;
    if (text) {
      toast.success(text);
    }
    const active = document.activeElement;
    if (after === "left" || (active instanceof HTMLElement && root.value?.contains(active) && active.matches(EDITABLE_FIELD))) {
      return;
    }
    if (after === "status") {
      statusCard.value?.focusHeading();
    } else {
      header.value?.focus();
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

// „Odśwież” is gone with the message about a failed read (of the end or of the start), so the focus goes to the heading
// of the card; when the read fails as well, the message of the load replaces the form and the card with it, so it goes
// to the H1.
async function refreshAfterRead(): Promise<void> {
  await refresh();
  await nextTick();
  if (statusCard.value === null) {
    header.value?.focus();
  } else {
    statusCard.value.focusHeading();
  }
}

const { open: leaveOpen, leave } = useUnsavedChangesGuard(() => dirty.value);

// Ending and deleting cannot be undone, so they ask first (`ConfirmDialog`: „Wróć” has the focus, Escape closes).
const endOpen = ref(false);
const deleteOpen = ref(false);

const pickerOpen = ref(false);
const variantsLocked = computed(() => readOnly.value || scheduleLocked.value);
const chosenIds = computed(() => data.value.variants.map((variant) => variant.variantId));
const startDisabled = computed(() => variantsLocked.value || data.value.startNow);

async function togglePicker(): Promise<void> {
  pickerOpen.value = !pickerOpen.value;
  if (pickerOpen.value) {
    await nextTick();
    picker.value?.focusSearch();
  }
}

async function closePicker(): Promise<void> {
  pickerOpen.value = false;
  await nextTick();
  (opener.value?.$el as HTMLElement | undefined)?.focus();
}

// The button of the removed row is gone: the focus goes to the button of the next row, and without one to the search of
// the open picker, else to the button that opens it (#304 F4).
async function removeChosen(variantId: string): Promise<void> {
  const index = data.value.variants.findIndex((variant) => variant.variantId === variantId);
  const nextId = data.value.variants[index + 1]?.variantId;
  removeVariant(variantId);
  await nextTick();
  const next = [...(root.value?.querySelectorAll<HTMLElement>("[data-testid=variant-row]") ?? [])].find((row) => row.dataset.variantId === nextId);
  const nextButton = next?.querySelector<HTMLElement>("[data-testid=remove-variant]");
  if (nextButton) {
    nextButton.focus();
  } else if (pickerOpen.value) {
    picker.value?.focusSearch();
  } else {
    (opener.value?.$el as HTMLElement | undefined)?.focus();
  }
}

// The name of a chosen variant in the name of its button: the product, and the label unless it is the default variant.
function chosenName(productName: string | null, label: string | null): string {
  if (productName === null) {
    return "wariant usunięty";
  }
  return label === null || label === "" ? productName : `${productName} / ${label}`;
}

const title = computed(() => {
  if (loadError.value) {
    return "Nie udało się wczytać promocji";
  }
  return view.value ? data.value.name || "Promocja" : isExisting.value ? "Promocja" : "Nowa promocja";
});
const crumbs = computed(() => [
  { label: "Marketing" },
  { label: "Promocje", to: { name: "promotions" } },
  { label: loadError.value ? "Promocja" : title.value },
]);

// What explains a disabled „Zakończ teraz” or „Usuń”: the wait for the end or for the start, or the changes to save first.
const actionReason = computed(() => (ending.value ? ids.ending : starting.value ? ids.starting : dirty.value ? ids.reason : undefined));
// What explains a disabled „Zapisz”: the wait for the end or for the start.
const saveReason = computed(() => (ending.value ? ids.ending : starting.value ? ids.starting : undefined));
// A read of the promotion after „Zakończ teraz” or after a save „od teraz” that failed or ran out of time.
const readError = computed(() => endError.value ?? startError.value);

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
            :to="{ name: 'promotions' }"
          >
            Wróć do listy promocji
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

      <!-- From 1280 px two columns: the data on the left, the side column of 320 px on the right. Below that one column
           with the side card above the data, which is the order of the source. -->
      <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
        <div
          v-if="view"
          class="flex flex-col gap-6 xl:col-start-2 xl:row-start-1"
        >
          <FormSection
            ref="statusCard"
            title="Status"
          >
            <div class="flex flex-wrap items-center gap-2">
              <Badge
                data-testid="promotion-status"
                :variant="statusVariant(view.status)"
              >
                {{ statusLabel(view.status) }}
              </Badge>
            </div>
            <p
              v-if="!canManagePromotions(auth.staff)"
              data-testid="read-only-note"
              class="text-sm text-muted-foreground"
            >
              Nie masz uprawnień do zmiany promocji. Widok jest tylko do odczytu.
            </p>
            <p
              v-else-if="promotionStatus === 'ended'"
              data-testid="read-only-note"
              class="text-sm text-muted-foreground"
            >
              Zakończonej promocji nie można zmienić. Widok jest tylko do odczytu.
            </p>

            <!-- A status region that is there before its text: what appears in it is announced. -->
            <div
              role="status"
              class="flex flex-col gap-2 empty:hidden"
            >
              <p
                v-if="ending"
                :id="ids.ending"
                data-testid="ending-note"
                class="text-sm text-muted-foreground"
              >
                Promocja kończy się.
              </p>
              <p
                v-if="endNotice"
                data-testid="end-notice"
                class="text-sm"
              >
                {{ endNotice }}
              </p>
              <p
                v-if="starting"
                :id="ids.starting"
                data-testid="starting-note"
                class="text-sm text-muted-foreground"
              >
                Promocja rozpoczyna się.
              </p>
              <p
                v-if="startNotice"
                data-testid="start-notice"
                class="text-sm"
              >
                {{ startNotice }}
              </p>
            </div>
            <Alert
              v-if="readError"
              :data-testid="endError ? 'end-error' : 'start-error'"
              variant="destructive"
              :class="ERROR_ALERT"
            >
              <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span>{{ readError }}</span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  data-testid="end-refresh"
                  @click="refreshAfterRead"
                >
                  Odśwież
                </Button>
              </AlertDescription>
            </Alert>

            <template v-if="!readOnly">
              <Button
                v-if="promotionStatus === 'active'"
                type="button"
                variant="outline"
                data-testid="end-now"
                :disabled="!canEndNow"
                :aria-describedby="actionReason"
                @click="endOpen = true"
              >
                <CircleStop aria-hidden="true" />
                Zakończ teraz
              </Button>
              <Button
                v-if="promotionStatus === 'planned'"
                type="button"
                variant="outline"
                data-testid="delete"
                :disabled="!canDelete"
                :aria-describedby="actionReason"
                @click="deleteOpen = true"
              >
                <Trash2 aria-hidden="true" />
                Usuń
              </Button>
              <p
                v-if="dirty && !ending && !starting"
                :id="ids.reason"
                data-testid="action-reason"
                class="text-sm text-muted-foreground"
              >
                Najpierw zapisz zmiany.
              </p>
            </template>
          </FormSection>
        </div>

        <!-- While a save runs, while the form waits for the start of a promotion saved „od teraz” and while „Odśwież”
             reads the promotion, nothing can be typed or chosen: the answer rebuilds the form from the API and would
             overwrite it (#580). -->
        <fieldset
          data-testid="promotion-fields"
          :disabled="locked"
          class="flex min-w-0 flex-col gap-6 xl:col-start-1 xl:row-start-1"
        >
          <FormSection title="Dane promocji">
            <p
              v-if="scheduleLocked && !readOnly"
              data-testid="schedule-locked-note"
              class="text-sm text-muted-foreground"
            >
              Promocja trwa. Możesz zmienić jej nazwę i koniec; procent, początek i warianty są zablokowane.
            </p>

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
                  maxlength="100"
                  autocomplete="off"
                  :disabled="readOnly"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
            </FormField>

            <FormField
              :control-id="ids.percent"
              label="Obniżka (%)"
              :errors="errorsAt('percentOff')"
              error-testid="percent-error"
            >
              <template #default="{ describedBy, invalid }">
                <Input
                  :id="ids.percent"
                  v-model="data.percentOff"
                  data-testid="percent-input"
                  inputmode="numeric"
                  autocomplete="off"
                  :disabled="variantsLocked"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
            </FormField>

            <FormField
              :control-id="ids.starts"
              label="Początek"
              :errors="errorsAt('startsAt')"
              error-testid="starts-error"
            >
              <template #default="{ describedBy, invalid }">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                  <Input
                    :id="ids.starts"
                    v-model="data.startsAt"
                    data-testid="starts-input"
                    type="datetime-local"
                    class="w-auto"
                    :disabled="startDisabled"
                    :aria-invalid="invalid ? 'true' : undefined"
                    :aria-describedby="describedBy"
                  />
                  <div class="flex items-center gap-3">
                    <Switch
                      :id="ids.startNow"
                      v-model="data.startNow"
                      data-testid="start-now"
                      :disabled="variantsLocked"
                    />
                    <Label :for="ids.startNow">
                      Od teraz
                    </Label>
                  </div>
                </div>
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
                  class="w-auto"
                  :disabled="readOnly"
                  :aria-invalid="invalid ? 'true' : undefined"
                  :aria-describedby="describedBy"
                />
              </template>
            </FormField>
            <p class="text-sm text-muted-foreground">
              Czas w strefie Europe/Warsaw (czas polski).
            </p>
          </FormSection>

          <FormSection title="Warianty">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <p
                data-testid="variants-summary"
                class="text-sm text-muted-foreground"
              >
                Wybrane warianty: {{ data.variants.length }}
              </p>
              <Button
                v-if="!variantsLocked"
                ref="opener"
                type="button"
                variant="outline"
                data-testid="open-picker"
                :aria-expanded="pickerOpen"
                @click="togglePicker"
              >
                <Plus aria-hidden="true" />
                Dodaj warianty
              </Button>
            </div>
            <p
              v-for="text in errorsAt('variantIds')"
              :key="text"
              data-testid="variants-error"
              data-form-error
              class="text-sm text-status-danger"
            >
              {{ text }}
            </p>

            <PromotionVariantPicker
              v-if="pickerOpen && !variantsLocked"
              ref="picker"
              :chosen="chosenIds"
              @add="addVariants"
              @close="closePicker"
            />

            <p
              v-if="data.variants.length === 0"
              data-testid="variants-empty"
              class="py-4 text-center text-sm text-muted-foreground"
            >
              Nie wybrano wariantów.
            </p>
            <div
              v-else
              role="region"
              aria-label="Wybrane warianty"
              tabindex="0"
              class="overflow-x-auto"
            >
              <table class="w-full caption-bottom text-sm">
                <TableHeader>
                  <TableRow class="bg-muted hover:bg-muted">
                    <TableHead
                      scope="col"
                      class="px-2.5 text-xs font-bold whitespace-normal text-heading uppercase"
                    >
                      Produkt
                    </TableHead>
                    <TableHead
                      scope="col"
                      class="px-2.5 text-xs font-bold whitespace-normal text-heading uppercase"
                    >
                      Wariant
                    </TableHead>
                    <TableHead
                      scope="col"
                      class="px-2.5 text-right text-xs font-bold whitespace-normal text-heading uppercase"
                    >
                      Cena katalogowa
                    </TableHead>
                    <TableHead
                      scope="col"
                      class="px-2.5 text-right text-xs font-bold whitespace-normal text-heading uppercase"
                    >
                      Cena promocyjna
                    </TableHead>
                    <TableHead
                      v-if="!variantsLocked"
                      scope="col"
                      class="px-2.5 text-right text-xs font-bold whitespace-normal text-heading uppercase"
                    >
                      Akcje
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow
                    v-for="row in data.variants"
                    :key="row.variantId"
                    data-testid="variant-row"
                    :data-variant-id="row.variantId"
                  >
                    <TableCell class="px-2.5 py-3 whitespace-normal">
                      <div class="flex flex-wrap items-center gap-2">
                        <Badge
                          v-if="row.productName === null"
                          data-testid="variant-deleted"
                          variant="warning"
                        >
                          Wariant usunięty
                        </Badge>
                        <span
                          v-else
                          data-testid="variant-name"
                          class="font-bold text-heading"
                        >{{ row.productName }}</span>
                        <Badge
                          v-if="isConflicting(row.variantId)"
                          data-testid="variant-conflict"
                          variant="danger"
                        >
                          <TriangleAlert aria-hidden="true" />
                          Koliduje z inną promocją
                        </Badge>
                      </div>
                    </TableCell>
                    <TableCell class="px-2.5 py-3 whitespace-normal">
                      <span data-testid="variant-label">{{ row.label === "" ? "Wariant domyślny" : (row.label ?? "") }}</span>
                    </TableCell>
                    <TableCell class="px-2.5 py-3 text-right tabular-nums">
                      <span data-testid="variant-regular">{{ row.regularGross === null ? "—" : formatMoney(row.regularGross, CURRENCY) }}</span>
                    </TableCell>
                    <TableCell class="px-2.5 py-3 text-right tabular-nums">
                      <span data-testid="variant-promotional">{{ row.promotionalGross === null ? "—" : formatMoney(row.promotionalGross, CURRENCY) }}</span>
                    </TableCell>
                    <TableCell
                      v-if="!variantsLocked"
                      class="px-2.5 py-3 text-right"
                    >
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-testid="remove-variant"
                        :aria-label="`Usuń: ${chosenName(row.productName, row.label)}`"
                        @click="removeChosen(row.variantId)"
                      >
                        <X aria-hidden="true" />
                        Usuń
                      </Button>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </table>
            </div>
          </FormSection>
        </fieldset>
      </div>

      <SaveBar
        v-if="!readOnly && (dirty || !isExisting)"
        :creating="!isExisting"
        new-label="Nowa promocja"
        :busy="busy"
        :save-disabled="!canSave"
        :save-described-by="saveReason"
        @save="run(save, 'page')"
        @discard="discardChanges"
        @cancel="router.push({ name: 'promotions' })"
      />
    </template>

    <ConfirmDialog
      v-if="view"
      v-model:open="endOpen"
      :title="`Zakończyć promocję ${view.name} teraz?`"
      description="Promocja przestanie obowiązywać od razu, a jej warianty wrócą do cen katalogowych. Tego nie można cofnąć."
      confirm-label="Zakończ promocję teraz"
      destructive
      :fallback-focus="statusCard?.heading ?? null"
      @confirm="run(endNow, 'status')"
    />
    <ConfirmDialog
      v-if="view"
      v-model:open="deleteOpen"
      :title="`Usunąć promocję ${view.name}?`"
      description="Promocja zostanie usunięta razem z listą jej wariantów. Tego nie można cofnąć."
      confirm-label="Usuń promocję"
      destructive
      :fallback-focus="statusCard?.heading ?? null"
      @confirm="run(remove, 'left', 'Promocja usunięta.')"
    />
    <UnsavedChangesDialog
      v-model:open="leaveOpen"
      :fallback-focus="header?.heading ?? null"
      @leave="leave"
    />
  </div>
</template>
