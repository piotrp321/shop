<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Dialog from "primevue/dialog";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import { computed, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";
import { actorLabel, cancellationReasonLabel, noteRequired, type OrderTransition, statusLabel, transitionLabel } from "@/orders/format";
import { type OrderChange, useOrderDetail } from "@/orders/useOrderDetail";
import { formatDateTime, formatMoney } from "@/prices/format";

const route = useRoute();
const {
  order,
  loading,
  error,
  forbidden,
  notFound,
  notFoundMessage,
  saving,
  actions,
  canCompleteRefund,
  retry,
  changeStatus,
  completeRefund,
} = useOrderDetail(() => String(route.params.orderId));

// The confirmation dialog: a change of the status or the completion of the refund, with a one-line note.
type Pending = { kind: "status"; transition: OrderTransition } | { kind: "refund" };
const pending = ref<Pending | null>(null);
const note = ref("");
const noteError = ref<string | null>(null);
const dialogMessage = ref<string | null>(null);
// After a conflict the order is reloaded and the page, not the dialog, says what happened.
const conflict = ref<string | null>(null);
// Counts the openings and closings: an answer reaches only the dialog it was sent from.
let session = 0;

const dialogVisible = computed({
  get: () => pending.value !== null,
  set: (visible: boolean) => {
    if (!visible) {
      closeDialog();
    }
  },
});
const dialogTitle = computed(() => {
  const current = pending.value;
  if (current === null) {
    return "";
  }
  return current.kind === "status" ? transitionLabel(current.transition) : "Zwrot pieniędzy wykonany";
});
const required = computed(() => pending.value?.kind === "status" && noteRequired(pending.value.transition));

function closeDialog(): void {
  session++;
  pending.value = null;
}

function openStatus(transition: OrderTransition): void {
  open({ kind: "status", transition });
}

function openRefund(): void {
  open({ kind: "refund" });
}

function open(next: Pending): void {
  session++;
  pending.value = next;
  note.value = "";
  noteError.value = null;
  dialogMessage.value = null;
}

async function submit(): Promise<void> {
  const current = pending.value;
  if (current === null || saving.value) {
    return;
  }
  noteError.value = null;
  dialogMessage.value = null;
  const sentFrom = session;
  const result = current.kind === "status" ? await changeStatus(current.transition, note.value) : await completeRefund(note.value);
  if (sentFrom !== session) {
    return;
  }
  applyResult(result);
}

function applyResult(result: OrderChange): void {
  switch (result.status) {
    case "saved":
      conflict.value = null;
      closeDialog();
      break;
    case "conflict":
      conflict.value = result.message;
      closeDialog();
      break;
    case "invalid":
      noteError.value = result.fields.note ?? null;
      dialogMessage.value = result.message;
      break;
    case "failed":
      dialogMessage.value = result.message;
      break;
    case "ignored":
      closeDialog();
      break;
  }
}
</script>

<template>
  <div class="order">
    <RouterLink
      data-testid="back"
      :to="{ name: 'orders' }"
      class="back"
    >
      <i class="pi pi-arrow-left" /> Zamówienia
    </RouterLink>

    <Message
      v-if="notFound"
      data-testid="not-found"
      severity="warn"
    >
      {{ notFoundMessage }}
      <RouterLink
        data-testid="to-list"
        :to="{ name: 'orders' }"
      >
        Wróć do listy zamówień
      </RouterLink>
    </Message>

    <Message
      v-if="error"
      data-testid="order-error"
      severity="error"
    >
      {{ error }}
      <Button
        v-if="!forbidden"
        data-testid="retry"
        label="Spróbuj ponownie"
        icon="pi pi-refresh"
        severity="secondary"
        size="small"
        :loading="loading"
        @click="retry()"
      />
    </Message>

    <Message
      v-if="conflict"
      data-testid="conflict"
      severity="warn"
    >
      {{ conflict }}
    </Message>

    <template v-if="order">
      <div class="heading">
        <h1>Zamówienie {{ order.number }}</h1>
        <Tag
          data-testid="status"
          :value="statusLabel(order.status).label"
          :severity="statusLabel(order.status).severity"
        />
      </div>

      <div
        v-if="actions.length > 0 || canCompleteRefund"
        class="actions"
        data-testid="actions"
      >
        <Button
          v-for="action in actions"
          :key="action.transition"
          :data-testid="`action-${action.transition}`"
          :label="action.label"
          :severity="action.transition === 'cancel' ? 'danger' : 'primary'"
          @click="openStatus(action.transition)"
        />
        <Button
          v-if="canCompleteRefund"
          data-testid="complete-refund"
          label="Zwrot pieniędzy wykonany"
          icon="pi pi-check"
          @click="openRefund()"
        />
      </div>

      <section
        class="grid"
      >
        <div class="panel">
          <h2>Klient</h2>
          <dl data-testid="contact">
            <dt>Imię i nazwisko</dt>
            <dd>{{ order.fullName }}</dd>
            <dt>E-mail</dt>
            <dd>{{ order.email }}</dd>
            <dt>Telefon</dt>
            <dd>{{ order.phone }}</dd>
          </dl>
        </div>
        <div class="panel">
          <h2>Adres dostawy</h2>
          <address data-testid="address">
            {{ order.shippingAddress.street }}<br>
            {{ order.shippingAddress.postalCode }} {{ order.shippingAddress.city }}<br>
            {{ order.shippingAddress.country }}
          </address>
        </div>
        <div class="panel">
          <h2>Płatność i daty</h2>
          <dl data-testid="dates">
            <dt>Złożone</dt>
            <dd>{{ formatDateTime(order.placedAt) }}</dd>
            <template v-if="order.status === 'new'">
              <dt>Termin płatności</dt>
              <dd data-testid="payment-deadline">
                {{ formatDateTime(order.paymentDeadline) }}
              </dd>
            </template>
            <template v-if="order.paidAt">
              <dt>Opłacone</dt>
              <dd>{{ formatDateTime(order.paidAt) }}</dd>
            </template>
            <template v-if="order.paymentId">
              <dt>Płatność</dt>
              <dd data-testid="payment-id">
                {{ order.paymentId }}
              </dd>
            </template>
            <template v-if="order.shippedAt">
              <dt>Wysłane</dt>
              <dd>{{ formatDateTime(order.shippedAt) }}</dd>
            </template>
            <template v-if="order.deliveredAt">
              <dt>Dostarczone</dt>
              <dd>{{ formatDateTime(order.deliveredAt) }}</dd>
            </template>
            <template v-if="order.cancelledAt">
              <dt>Anulowane</dt>
              <dd data-testid="cancelled-at">
                {{ formatDateTime(order.cancelledAt) }}<template v-if="cancellationReasonLabel(order.cancellationReason)">
                  ({{ cancellationReasonLabel(order.cancellationReason) }})
                </template>
              </dd>
            </template>
            <template v-if="order.returnedAt">
              <dt>Zwrócone</dt>
              <dd>{{ formatDateTime(order.returnedAt) }}</dd>
            </template>
          </dl>
        </div>
      </section>

      <section
        v-if="order.refund"
        class="panel"
        data-testid="refund"
      >
        <h2>Zwrot pieniędzy</h2>
        <dl>
          <dt>Kwota</dt>
          <dd data-testid="refund-amount">
            {{ formatMoney(order.refund.amount, order.refund.currency) }}
          </dd>
          <dt>Termin</dt>
          <dd>
            <span data-testid="refund-due">{{ formatDateTime(order.refund.dueAt) }}</span>
            <Tag
              v-if="order.refund.overdue"
              data-testid="refund-overdue"
              value="Po terminie"
              severity="danger"
              class="overdue"
            />
          </dd>
          <dt>Stan</dt>
          <dd data-testid="refund-state">
            {{ order.refund.completedAt ? "Wykonany" : "Do wykonania" }}
          </dd>
          <template v-if="order.refund.completedAt">
            <dt>Wykonany</dt>
            <dd data-testid="refund-completed">
              {{ formatDateTime(order.refund.completedAt) }}<span v-if="order.refund.completedByUsername">, {{ order.refund.completedByUsername }}</span>
            </dd>
            <template v-if="order.refund.note">
              <dt>Notatka</dt>
              <dd data-testid="refund-note">
                {{ order.refund.note }}
              </dd>
            </template>
          </template>
          <template v-if="order.paymentId">
            <dt>Płatność do zwrotu</dt>
            <dd data-testid="refund-payment-id">
              {{ order.paymentId }}
            </dd>
          </template>
        </dl>
      </section>

      <section class="panel">
        <h2>Pozycje</h2>
        <DataTable
          :value="order.lines"
          data-key="variantId"
          :pt="{ bodyRow: { 'data-testid': 'line-row' } }"
        >
          <Column header="Produkt">
            <template #body="{ data }">
              <span data-testid="line-name">{{ data.productName }}</span>
              <span
                v-if="data.variantLabel"
                class="variant"
              > ({{ data.variantLabel }})</span>
              <div
                v-if="data.promotion"
                data-testid="line-promotion"
                class="promotion-copy"
              >
                Promocja „{{ data.promotion.name }}”: −{{ data.promotion.percentOff }}% od ceny katalogowej
                {{ formatMoney(data.promotion.regularUnitGross, order.totals.currency) }}
              </div>
            </template>
          </Column>
          <Column
            header="Ilość"
            class="amount"
          >
            <template #body="{ data }">
              <span data-testid="line-quantity">{{ data.quantity }}</span>
            </template>
          </Column>
          <Column
            header="Cena brutto"
            class="amount"
          >
            <template #body="{ data }">
              {{ formatMoney(data.unitGross, order.totals.currency) }}
            </template>
          </Column>
          <Column
            v-if="order.discount"
            header="Wartość przed rabatem"
            class="amount"
          >
            <template #body="{ data }">
              <span data-testid="line-gross-before-discount">{{ formatMoney(data.grossBeforeDiscount, order.totals.currency) }}</span>
            </template>
          </Column>
          <Column
            v-if="order.discount"
            header="Rabat"
            class="amount"
          >
            <template #body="{ data }">
              <span data-testid="line-discount">{{ data.discountGross === 0 ? "—" : `−${formatMoney(data.discountGross, order.totals.currency)}` }}</span>
            </template>
          </Column>
          <Column
            :header="order.discount ? 'Wartość po rabacie' : 'Wartość brutto'"
            class="amount"
          >
            <template #body="{ data }">
              <span data-testid="line-gross">{{ formatMoney(data.lineGross, order.totals.currency) }}</span>
            </template>
          </Column>
        </DataTable>
        <p
          v-if="order.discount"
          data-testid="discount-line"
          class="discount-line"
        >
          Kod {{ order.discount.code }}: −{{ formatMoney(order.discount.amountGross, order.totals.currency) }}
        </p>
        <dl
          class="totals"
          data-testid="totals"
        >
          <dt>Netto</dt>
          <dd>{{ formatMoney(order.totals.net, order.totals.currency) }}</dd>
          <dt>VAT</dt>
          <dd>{{ formatMoney(order.totals.vat, order.totals.currency) }}</dd>
          <dt>{{ order.discount ? "Brutto po rabacie" : "Brutto" }}</dt>
          <dd data-testid="total-gross">
            {{ formatMoney(order.totals.gross, order.totals.currency) }}
          </dd>
        </dl>
      </section>

      <section class="panel">
        <h2>Historia statusów</h2>
        <DataTable
          :value="order.history"
          :pt="{ bodyRow: { 'data-testid': 'history-row' } }"
        >
          <Column header="Data">
            <template #body="{ data }">
              <span data-testid="history-at">{{ formatDateTime(data.occurredAt) }}</span>
            </template>
          </Column>
          <Column header="Zmiana">
            <template #body="{ data }">
              <span data-testid="history-change">{{ data.from ? `${statusLabel(data.from).label} → ` : "" }}{{ statusLabel(data.to).label }}</span>
            </template>
          </Column>
          <Column header="Kto">
            <template #body="{ data }">
              <span data-testid="history-actor">{{ actorLabel(data) }}</span>
            </template>
          </Column>
          <Column header="Notatka">
            <template #body="{ data }">
              <span data-testid="history-note">{{ data.note ?? "" }}</span>
            </template>
          </Column>
        </DataTable>
      </section>
    </template>
    <Skeleton
      v-else-if="loading"
      height="16rem"
    />

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="dialogTitle"
      :style="{ width: '28rem' }"
    >
      <div
        data-testid="change-dialog"
        class="change"
      >
        <strong v-if="order">Zamówienie {{ order.number }}</strong>
        <Message
          v-if="dialogMessage"
          data-testid="change-error"
          severity="error"
        >
          {{ dialogMessage }}
        </Message>
        <div class="field">
          <label for="change-note">{{ required ? "Notatka (wymagana)" : "Notatka (opcjonalnie)" }}</label>
          <InputText
            id="change-note"
            v-model="note"
            data-testid="change-note"
            maxlength="500"
            :invalid="noteError !== null"
            aria-describedby="change-note-error"
            @keydown.enter.prevent="submit()"
          />
          <Message
            v-if="noteError"
            id="change-note-error"
            data-testid="change-note-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ noteError }}
          </Message>
        </div>
        <div class="dialog-actions">
          <Button
            data-testid="change-cancel"
            label="Wróć"
            severity="secondary"
            @click="dialogVisible = false"
          />
          <Button
            data-testid="change-submit"
            :label="dialogTitle"
            icon="pi pi-check"
            :loading="saving"
            @click="submit()"
          />
        </div>
      </div>
    </Dialog>
  </div>
</template>

<style scoped>
.order {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.back {
  align-self: flex-start;
}

.heading {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.heading h1 {
  margin: 0;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 1rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 1rem;
  background: var(--p-content-background);
  border-radius: var(--p-content-border-radius);
}

.panel h2 {
  margin: 0;
  font-size: 1.1rem;
}

dl {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.25rem 1rem;
  margin: 0;
}

dt {
  color: var(--p-text-muted-color);
}

dd {
  margin: 0;
}

.totals {
  align-self: flex-end;
}

.variant {
  color: var(--p-text-muted-color);
}

.promotion-copy {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.discount-line {
  align-self: flex-end;
  margin: 0;
}

.overdue {
  margin-left: 0.5rem;
}

.amount {
  text-align: right;
  white-space: nowrap;
}

.change {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>
