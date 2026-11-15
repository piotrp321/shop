<script setup lang="ts">
import { Copy } from "lucide-vue-next";
import { computed, nextTick, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";
import { toast } from "vue-sonner";
import FormSection from "@/components/FormSection.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { focusAfterAction } from "@/lib/focus";
import { cancellationReasonLabel, noteRequired, type OrderTransition, statusLabel, transitionLabel } from "@/orders/format";
import OrderTimeline from "@/orders/OrderTimeline.vue";
import { type OrderChange, useOrderDetail } from "@/orders/useOrderDetail";
import { formatDateTime, formatMoney } from "@/prices/format";

const route = useRoute();
const { order, loading, error, forbidden, notFound, notFoundMessage, saving, actions, canCompleteRefund, retry, changeStatus, completeRefund } = useOrderDetail(() => String(route.params.orderId));

const header = ref<InstanceType<typeof PageHeader> | null>(null);
const actionsCard = ref<InstanceType<typeof FormSection> | null>(null);
const historyCard = ref<InstanceType<typeof FormSection> | null>(null);
const retryButton = ref<InstanceType<typeof Button> | null>(null);

// The text of an error is the colour of the status „danger” (#446): the contrast of the default one is lower on the card.
const ERROR_ALERT = "border-status-danger text-status-danger *:data-[slot=alert-description]:text-status-danger";

const title = computed(() => {
  if (order.value) {
    return `Zamówienie ${order.value.number}`;
  }
  if (notFound.value) {
    return "Nie znaleziono zamówienia";
  }
  return error.value ? "Nie udało się wczytać zamówienia" : "Zamówienie";
});
const crumbs = computed(() => [{ label: "Zamówienia", to: { name: "orders" } }, { label: order.value?.number ?? "Zamówienie" }]);
const showSkeleton = computed(() => order.value === null && loading.value && error.value === null && !notFound.value);

function elementOf(target: { $el: unknown } | null): HTMLElement | undefined {
  return (target?.$el as HTMLElement | undefined) ?? undefined;
}

// After a retry the button is gone when it worked, so the focus goes to the H1; when it failed again the button stays.
async function retryLoad(): Promise<void> {
  const trigger = elementOf(retryButton.value) ?? null;
  await retry();
  await nextTick();
  focusAfterAction(trigger, header.value?.heading ?? null);
}

// ---- Copying the payment id -------------------------------------------------------------------------------------

const COPIED = "Skopiowano";
const NOT_COPIED = "Nie udało się skopiować – zaznacz identyfikator ręcznie";
const paymentIdElement = ref<HTMLElement | null>(null);
const copyStatus = ref("");

async function copyPaymentId(): Promise<void> {
  const id = order.value?.paymentId;
  if (!id) {
    return;
  }
  try {
    // A missing clipboard (an insecure page) throws here as a refusal does.
    await navigator.clipboard.writeText(id);
    copyStatus.value = COPIED;
  } catch {
    copyStatus.value = NOT_COPIED;
    selectPaymentId();
  }
}

// Leaves the id selected, so that the staff member can copy it with the keyboard.
function selectPaymentId(): void {
  const element = paymentIdElement.value;
  const selection = window.getSelection();
  if (element === null || selection === null) {
    return;
  }
  const range = document.createRange();
  range.selectNodeContents(element);
  selection.removeAllRanges();
  selection.addRange(range);
}

// ---- The window of a change -------------------------------------------------------------------------------------

// A change of the status or the completion of the refund, with a one-line note.
type Pending = { kind: "status"; transition: OrderTransition } | { kind: "refund" };
const DIALOG_CLASS = "max-h-[calc(100dvh-2rem)] max-w-none overflow-y-auto sm:max-w-none";
const REFUND_LABEL = "Oznacz zwrot jako wykonany";

const dialogOpen = ref(false);
// What the window is about; it stays after the window closes, so that the title does not go empty while it fades.
const pending = ref<Pending | null>(null);
const note = ref("");
const noteError = ref<string | null>(null);
const dialogMessage = ref<string | null>(null);
// After a conflict the order is reloaded and the page, not the dialog, says what happened.
const conflict = ref<string | null>(null);
// Counts the openings and closings: an answer reaches only the window it was sent from.
let session = 0;
// The button that opened the window, and where the focus goes when it has closed.
let opener: HTMLElement | null = null;
let afterClose: "done" | "conflict" | null = null;

const noteInput = ref<InstanceType<typeof Input> | null>(null);
const changeAlert = ref<InstanceType<typeof Alert> | null>(null);
const conflictAlert = ref<InstanceType<typeof Alert> | null>(null);

const dialogTitle = computed(() => {
  const current = pending.value;
  const number = order.value?.number ?? "";
  if (current === null) {
    return "";
  }
  if (current.kind === "refund") {
    return `Oznaczyć zwrot pieniędzy za ${number} jako wykonany?`;
  }
  switch (current.transition) {
    case "cancel":
      return `Anulować zamówienie ${number}?`;
    case "ship":
      return `Oznaczyć zamówienie ${number} jako wysłane?`;
    case "deliver":
      return `Oznaczyć zamówienie ${number} jako dostarczone?`;
    case "return":
      return `Przyjąć zwrot zamówienia ${number}?`;
    default:
      return `${transitionLabel(current.transition)}: ${number}?`;
  }
});
const submitLabel = computed(() => (pending.value?.kind === "status" ? transitionLabel(pending.value.transition) : REFUND_LABEL));
const destructive = computed(() => pending.value?.kind === "status" && (pending.value.transition === "cancel" || pending.value.transition === "return"));
const required = computed(() => pending.value?.kind === "status" && noteRequired(pending.value.transition));

// The screen moves to another order (Back, a link): the window and the conflict were about the one it left.
watch(
  () => route.params.orderId,
  () => {
    conflict.value = null;
    afterClose = null;
    closeDialog();
  },
);

function closeDialog(): void {
  session++;
  dialogOpen.value = false;
}

function onOpenChange(open: boolean): void {
  if (!open) {
    closeDialog();
  }
}

function open(next: Pending, trigger: EventTarget | null): void {
  session++;
  opener = trigger instanceof HTMLElement ? trigger : null;
  afterClose = null;
  pending.value = next;
  note.value = "";
  noteError.value = null;
  dialogMessage.value = null;
  dialogOpen.value = true;
}

// The window opens on its field, however it was opened.
function focusNote(event: Event): void {
  event.preventDefault();
  elementOf(noteInput.value)?.focus();
}

// The focus never falls to the body: after a change to the heading of the card that took the button's place, after the
// conflict to its message, after „Wróć” or Escape back to the button that opened the window.
function onCloseAutoFocus(event: Event): void {
  event.preventDefault();
  const mode = afterClose;
  const trigger = opener;
  afterClose = null;
  opener = null;
  void nextTick(() => {
    if (mode === "conflict") {
      elementOf(conflictAlert.value)?.focus();
    } else if (mode === "done") {
      focusDoneTarget();
    } else {
      focusAfterAction(trigger, actionsCard.value?.heading ?? historyCard.value?.heading ?? null);
    }
  });
}

// The card „Akcje” has a new set of buttons; when it is gone (no further action) the focus goes to „Historia”.
function focusDoneTarget(): void {
  const target = actionsCard.value ?? historyCard.value;
  target?.focusHeading();
}

async function focusProblem(): Promise<void> {
  await nextTick();
  elementOf(noteError.value !== null ? noteInput.value : changeAlert.value)?.focus();
}

async function submit(): Promise<void> {
  const current = pending.value;
  if (current === null || !dialogOpen.value || saving.value) {
    return;
  }
  noteError.value = null;
  dialogMessage.value = null;
  const sentFrom = session;
  const result = current.kind === "status" ? await changeStatus(current.transition, note.value) : await completeRefund(note.value);
  if (sentFrom !== session) {
    return;
  }
  applyResult(result, current);
}

function applyResult(result: OrderChange, sent: Pending): void {
  switch (result.status) {
    case "saved":
      conflict.value = null;
      afterClose = "done";
      // The order is the one the API answered with, so the name of the status is the API's (an unknown one as it came).
      toast.success(sent.kind === "refund" ? "Zwrot oznaczony jako wykonany." : `Status zamówienia zmieniony: ${statusName(order.value?.status)}.`);
      closeDialog();
      break;
    case "conflict":
      conflict.value = result.message;
      afterClose = "conflict";
      closeDialog();
      break;
    case "invalid":
      noteError.value = result.fields.note ?? null;
      dialogMessage.value = result.message;
      void focusProblem();
      break;
    case "failed":
      dialogMessage.value = result.message;
      void focusProblem();
      break;
    case "ignored":
      closeDialog();
      break;
  }
}

function statusName(status: string | undefined): string {
  return status === undefined ? "" : statusLabel(status as never).label || status;
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      ref="header"
      :title="title"
      :crumbs="crumbs"
    >
      <template
        v-if="order"
        #status
      >
        <Badge
          data-testid="status"
          :variant="statusLabel(order.status).variant"
        >
          {{ statusLabel(order.status).label }}
        </Badge>
      </template>
    </PageHeader>

    <Alert
      v-if="notFound"
      data-testid="not-found"
      variant="destructive"
      :class="ERROR_ALERT"
    >
      <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span>{{ notFoundMessage }}</span>
        <Button
          as-child
          variant="outline"
          size="sm"
        >
          <RouterLink
            data-testid="to-list"
            :to="{ name: 'orders' }"
          >
            Wróć do listy zamówień
          </RouterLink>
        </Button>
      </AlertDescription>
    </Alert>

    <Alert
      v-if="error"
      data-testid="order-error"
      variant="destructive"
      :class="ERROR_ALERT"
    >
      <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span>{{ error }}</span>
        <Button
          v-if="!forbidden"
          ref="retryButton"
          type="button"
          variant="outline"
          size="sm"
          data-testid="retry"
          @click="retryLoad"
        >
          Spróbuj ponownie
        </Button>
      </AlertDescription>
    </Alert>

    <Alert
      v-if="conflict"
      ref="conflictAlert"
      data-testid="conflict"
      tabindex="-1"
    >
      <AlertDescription>{{ conflict }}</AlertDescription>
    </Alert>

    <Skeleton
      v-if="showSkeleton"
      data-testid="order-loading"
      class="h-80 w-full"
    />

    <!-- From 1280 px two columns: the main one on the left, the side column of 320 px on the right. Below that one
         column with the side one above, which is the order of the source (and of Tab). -->
    <div
      v-if="order"
      class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start"
    >
      <div
        data-testid="order-side"
        class="flex min-w-0 flex-col gap-6 xl:col-start-2 xl:row-start-1"
      >
        <FormSection
          v-if="actions.length > 0 || canCompleteRefund"
          ref="actionsCard"
          title="Akcje"
          data-testid="actions"
        >
          <div class="flex flex-wrap gap-2">
            <Button
              v-for="action in actions"
              :key="action.transition"
              type="button"
              :variant="action.transition === 'cancel' ? 'destructive' : 'interface'"
              :data-testid="`action-${action.transition}`"
              @click="open({ kind: 'status', transition: action.transition }, $event.currentTarget)"
            >
              {{ action.label }}
            </Button>
            <Button
              v-if="canCompleteRefund"
              type="button"
              variant="interface"
              data-testid="complete-refund"
              @click="open({ kind: 'refund' }, $event.currentTarget)"
            >
              {{ REFUND_LABEL }}
            </Button>
          </div>
        </FormSection>

        <FormSection title="Klient">
          <dl
            data-testid="contact"
            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm"
          >
            <dt class="text-muted-foreground">
              Imię i nazwisko
            </dt>
            <dd class="min-w-0 break-words">
              {{ order.fullName }}
            </dd>
            <dt class="text-muted-foreground">
              E-mail
            </dt>
            <dd class="min-w-0">
              <a
                :href="`mailto:${order.email}`"
                class="rounded-sm break-all text-interface underline underline-offset-4"
              >{{ order.email }}</a>
            </dd>
            <dt class="text-muted-foreground">
              Telefon
            </dt>
            <dd class="min-w-0 break-words">
              {{ order.phone }}
            </dd>
          </dl>
          <RouterLink
            data-testid="other-orders"
            class="w-fit rounded-sm text-sm text-interface underline underline-offset-4"
            :to="{ name: 'orders', query: { q: order.email } }"
          >
            Inne zamówienia klienta
          </RouterLink>
        </FormSection>

        <FormSection title="Adres dostawy">
          <address
            data-testid="address"
            class="text-sm not-italic"
          >
            {{ order.shippingAddress.street }}<br>
            {{ order.shippingAddress.postalCode }} {{ order.shippingAddress.city }}<br>
            {{ order.shippingAddress.country }}
          </address>
        </FormSection>

        <FormSection title="Płatność">
          <dl
            data-testid="dates"
            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm"
          >
            <dt class="text-muted-foreground">
              Złożone
            </dt>
            <dd class="min-w-0">
              {{ formatDateTime(order.placedAt) }}
            </dd>
            <template v-if="order.status === 'new'">
              <dt class="text-muted-foreground">
                Termin płatności
              </dt>
              <dd
                data-testid="payment-deadline"
                class="min-w-0"
              >
                {{ formatDateTime(order.paymentDeadline) }}
              </dd>
            </template>
            <template v-if="order.paidAt">
              <dt class="text-muted-foreground">
                Opłacone
              </dt>
              <dd class="min-w-0">
                {{ formatDateTime(order.paidAt) }}
              </dd>
            </template>
            <template v-if="order.shippedAt">
              <dt class="text-muted-foreground">
                Wysłane
              </dt>
              <dd class="min-w-0">
                {{ formatDateTime(order.shippedAt) }}
              </dd>
            </template>
            <template v-if="order.deliveredAt">
              <dt class="text-muted-foreground">
                Dostarczone
              </dt>
              <dd class="min-w-0">
                {{ formatDateTime(order.deliveredAt) }}
              </dd>
            </template>
            <template v-if="order.cancelledAt">
              <dt class="text-muted-foreground">
                Anulowane
              </dt>
              <dd
                data-testid="cancelled-at"
                class="min-w-0 break-words"
              >
                {{ formatDateTime(order.cancelledAt) }}{{ cancellationReasonLabel(order.cancellationReason) ? ` (${cancellationReasonLabel(order.cancellationReason)})` : "" }}
              </dd>
            </template>
            <template v-if="order.returnedAt">
              <dt class="text-muted-foreground">
                Zwrócone
              </dt>
              <dd class="min-w-0">
                {{ formatDateTime(order.returnedAt) }}
              </dd>
            </template>
          </dl>
          <div
            v-if="order.paymentId"
            class="flex flex-col gap-1.5 text-sm"
          >
            <span class="text-muted-foreground">Identyfikator płatności</span>
            <code
              ref="paymentIdElement"
              data-testid="payment-id"
              class="font-mono text-xs break-all"
            >{{ order.paymentId }}</code>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
              <Button
                type="button"
                variant="outline"
                size="sm"
                data-testid="copy-payment-id"
                aria-label="Kopiuj identyfikator płatności"
                @click="copyPaymentId"
              >
                <Copy aria-hidden="true" />
                Kopiuj
              </Button>
              <p
                role="status"
                data-testid="copy-status"
                class="text-xs text-muted-foreground empty:hidden"
              >
                {{ copyStatus }}
              </p>
            </div>
          </div>
        </FormSection>
      </div>

      <div
        data-testid="order-main"
        class="flex min-w-0 flex-col gap-6 xl:col-start-1 xl:row-start-1"
      >
        <FormSection title="Pozycje">
          <div
            role="region"
            aria-label="Pozycje zamówienia"
            tabindex="0"
            class="overflow-x-auto"
          >
            <table
              data-testid="lines"
              class="w-full caption-bottom text-sm"
            >
              <TableHeader>
                <TableRow class="hover:bg-transparent">
                  <TableHead class="px-2 text-xs font-bold whitespace-normal text-heading uppercase">
                    Produkt
                  </TableHead>
                  <TableHead class="px-2 text-right text-xs font-bold whitespace-normal text-heading uppercase">
                    Ilość
                  </TableHead>
                  <TableHead class="hidden px-2 text-right text-xs font-bold whitespace-normal text-heading uppercase sm:table-cell">
                    Cena brutto
                  </TableHead>
                  <TableHead
                    v-if="order.discount"
                    class="hidden px-2 text-right text-xs font-bold whitespace-normal text-heading uppercase sm:table-cell"
                  >
                    Wartość przed rabatem
                  </TableHead>
                  <TableHead
                    v-if="order.discount"
                    class="hidden px-2 text-right text-xs font-bold whitespace-normal text-heading uppercase sm:table-cell"
                  >
                    Rabat
                  </TableHead>
                  <TableHead class="px-2 text-right text-xs font-bold whitespace-normal text-heading uppercase">
                    {{ order.discount ? "Wartość po rabacie" : "Wartość brutto" }}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow
                  v-for="line in order.lines"
                  :key="line.variantId"
                  data-testid="line-row"
                >
                  <TableCell class="px-2 py-3 whitespace-normal">
                    <span
                      data-testid="line-name"
                      class="font-semibold break-words"
                    >{{ line.productName }}</span>
                    <span
                      v-if="line.variantLabel"
                      class="text-muted-foreground"
                    > ({{ line.variantLabel }})</span>
                    <div
                      v-if="line.promotion"
                      data-testid="line-promotion"
                      class="text-xs text-muted-foreground"
                    >
                      Promocja „{{ line.promotion.name }}”: −{{ line.promotion.percentOff }}% od ceny katalogowej
                      {{ formatMoney(line.promotion.regularUnitGross, order.totals.currency) }}
                    </div>
                    <div class="mt-1 flex flex-col text-xs text-muted-foreground sm:hidden">
                      <span data-testid="line-meta-unit">Cena brutto: {{ formatMoney(line.unitGross, order.totals.currency) }}</span>
                      <template v-if="order.discount">
                        <span data-testid="line-meta-before-discount">Wartość przed rabatem: {{ formatMoney(line.grossBeforeDiscount, order.totals.currency) }}</span>
                        <span data-testid="line-meta-discount">Rabat: {{ line.discountGross === 0 ? "—" : `−${formatMoney(line.discountGross, order.totals.currency)}` }}</span>
                      </template>
                    </div>
                  </TableCell>
                  <TableCell class="px-2 py-3 text-right tabular-nums">
                    <span data-testid="line-quantity">{{ line.quantity }}</span>
                  </TableCell>
                  <TableCell class="hidden px-2 py-3 text-right whitespace-nowrap tabular-nums sm:table-cell">
                    {{ formatMoney(line.unitGross, order.totals.currency) }}
                  </TableCell>
                  <TableCell
                    v-if="order.discount"
                    class="hidden px-2 py-3 text-right whitespace-nowrap tabular-nums sm:table-cell"
                  >
                    <span data-testid="line-gross-before-discount">{{ formatMoney(line.grossBeforeDiscount, order.totals.currency) }}</span>
                  </TableCell>
                  <TableCell
                    v-if="order.discount"
                    class="hidden px-2 py-3 text-right whitespace-nowrap tabular-nums sm:table-cell"
                  >
                    <span data-testid="line-discount">{{ line.discountGross === 0 ? "—" : `−${formatMoney(line.discountGross, order.totals.currency)}` }}</span>
                  </TableCell>
                  <TableCell class="px-2 py-3 text-right font-semibold whitespace-nowrap tabular-nums">
                    <span data-testid="line-gross">{{ formatMoney(line.lineGross, order.totals.currency) }}</span>
                  </TableCell>
                </TableRow>
              </TableBody>
            </table>
          </div>
          <p
            v-if="order.discount"
            data-testid="discount-line"
            class="self-end text-sm"
          >
            Kod {{ order.discount.code }}: −{{ formatMoney(order.discount.amountGross, order.totals.currency) }}
          </p>
          <dl
            class="grid grid-cols-[auto_auto] gap-x-6 gap-y-1 self-end text-sm"
            data-testid="totals"
          >
            <dt class="text-muted-foreground">
              Netto
            </dt>
            <dd class="text-right tabular-nums">
              {{ formatMoney(order.totals.net, order.totals.currency) }}
            </dd>
            <dt class="text-muted-foreground">
              VAT
            </dt>
            <dd class="text-right tabular-nums">
              {{ formatMoney(order.totals.vat, order.totals.currency) }}
            </dd>
            <dt class="font-bold">
              {{ order.discount ? "Brutto po rabacie" : "Brutto" }}
            </dt>
            <dd
              data-testid="total-gross"
              class="text-right font-bold tabular-nums"
            >
              {{ formatMoney(order.totals.gross, order.totals.currency) }}
            </dd>
          </dl>
        </FormSection>

        <FormSection
          ref="historyCard"
          title="Historia"
        >
          <OrderTimeline :history="order.history" />
        </FormSection>

        <FormSection
          v-if="order.refund"
          title="Zwrot pieniędzy"
          data-testid="refund"
        >
          <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-muted-foreground">
              Kwota
            </dt>
            <dd
              data-testid="refund-amount"
              class="min-w-0 tabular-nums"
            >
              {{ formatMoney(order.refund.amount, order.refund.currency) }}
            </dd>
            <dt class="text-muted-foreground">
              Termin
            </dt>
            <dd class="flex min-w-0 flex-wrap items-center gap-2">
              <span data-testid="refund-due">{{ formatDateTime(order.refund.dueAt) }}</span>
              <Badge
                v-if="order.refund.overdue"
                data-testid="refund-overdue"
                variant="danger"
              >
                Po terminie
              </Badge>
            </dd>
            <dt class="text-muted-foreground">
              Stan
            </dt>
            <dd
              data-testid="refund-state"
              class="min-w-0"
            >
              {{ order.refund.completedAt ? "Wykonany" : "Do wykonania" }}
            </dd>
            <template v-if="order.refund.completedAt">
              <dt class="text-muted-foreground">
                Wykonany
              </dt>
              <dd
                data-testid="refund-completed"
                class="min-w-0 break-words"
              >
                {{ formatDateTime(order.refund.completedAt) }}<span v-if="order.refund.completedByUsername">, {{ order.refund.completedByUsername }}</span>
              </dd>
              <template v-if="order.refund.note">
                <dt class="text-muted-foreground">
                  Notatka
                </dt>
                <dd
                  data-testid="refund-note"
                  class="min-w-0 break-words whitespace-pre-line"
                >
                  {{ order.refund.note }}
                </dd>
              </template>
            </template>
            <template v-if="order.paymentId">
              <dt class="text-muted-foreground">
                Płatność do zwrotu
              </dt>
              <dd
                data-testid="refund-payment-id"
                class="min-w-0 font-mono text-xs break-all"
              >
                {{ order.paymentId }}
              </dd>
            </template>
          </dl>
        </FormSection>
      </div>
    </div>

    <Dialog
      :open="dialogOpen"
      @update:open="onOpenChange"
    >
      <DialogContent
        data-testid="change-dialog"
        :class="`${DIALOG_CLASS} w-[min(28rem,calc(100vw-2rem))]`"
        @open-auto-focus="focusNote"
        @close-auto-focus="onCloseAutoFocus"
      >
        <DialogHeader>
          <DialogTitle>{{ dialogTitle }}</DialogTitle>
          <DialogDescription class="font-semibold text-foreground">
            Zamówienie {{ order?.number }}
          </DialogDescription>
        </DialogHeader>
        <Alert
          v-if="dialogMessage"
          ref="changeAlert"
          data-testid="change-error"
          variant="destructive"
          tabindex="-1"
        >
          <AlertDescription>{{ dialogMessage }}</AlertDescription>
        </Alert>
        <div class="flex flex-col gap-1.5">
          <Label for="change-note">
            {{ required ? "Notatka (wymagana)" : "Notatka (opcjonalnie)" }}
          </Label>
          <Input
            id="change-note"
            ref="noteInput"
            v-model="note"
            data-testid="change-note"
            maxlength="500"
            autocomplete="off"
            :aria-invalid="noteError !== null ? 'true' : undefined"
            :aria-describedby="noteError !== null ? 'change-note-error' : undefined"
            @keydown.enter.prevent="submit()"
          />
          <p
            v-if="noteError"
            id="change-note-error"
            data-testid="change-note-error"
            data-form-error
            class="text-sm text-status-danger"
          >
            {{ noteError }}
          </p>
        </div>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            data-testid="change-cancel"
            @click="closeDialog()"
          >
            Wróć
          </Button>
          <Button
            type="button"
            :variant="destructive ? 'destructive' : 'interface'"
            data-testid="change-submit"
            :disabled="saving"
            @click="submit()"
          >
            {{ submitLabel }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
