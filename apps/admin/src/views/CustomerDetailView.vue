<script setup lang="ts">
import { useMediaQuery } from "@vueuse/core";
import { computed, nextTick, ref, useId } from "vue";
import { RouterLink, useRoute } from "vue-router";
import FormSection from "@/components/FormSection.vue";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import ListEmpty from "@/components/list/ListEmpty.vue";
import ListPager from "@/components/list/ListPager.vue";
import ListToolbar from "@/components/list/ListToolbar.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { EMPTY_MESSAGE, type CustomerOrderItem, useCustomerOrders } from "@/customers/useCustomerOrders";
import { useCustomerDetail } from "@/customers/useCustomerDetail";
import { focusAfterAction } from "@/lib/focus";
import { statusLabel } from "@/orders/format";
import { formatDateTime, formatMoney } from "@/prices/format";

const route = useRoute();
const customerId = () => String(route.params.customerId);
const { customer, loading, error, forbidden, notFound, notFoundMessage, retry } = useCustomerDetail(customerId);
const orders = useCustomerOrders(customerId);

// One layout at a time: a table from 640 px, cards below it (the rows and their test ids are never in the DOM twice).
const tableLayout = useMediaQuery("(min-width: 640px)");

const header = ref<InstanceType<typeof PageHeader> | null>(null);
const retryButton = ref<InstanceType<typeof Button> | null>(null);
const ordersHeading = useId();

// The text of an error is the colour of the status „danger” (#446): the contrast of the default one is lower on the card.
const ERROR_ALERT = "border-status-danger text-status-danger *:data-[slot=alert-description]:text-status-danger";

const title = computed(() => {
  if (customer.value) {
    return customer.value.contact.fullName;
  }
  if (notFound.value) {
    return "Nie znaleziono klienta";
  }
  return error.value ? "Nie udało się wczytać klienta" : "Klient";
});
const crumbs = computed(() => [{ label: "Klienci", to: { name: "customers" } }, { label: customer.value?.contact.fullName ?? "Klient" }]);
const showSkeleton = computed(() => customer.value === null && loading.value && error.value === null && !notFound.value);

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

const dateOf = (order: CustomerOrderItem) => formatDateTime(order.placedAt);

const columns = computed<ListColumn<CustomerOrderItem>[]>(() => [
  { key: "number", header: "Numer", main: true },
  { key: "placedAt", header: "Data", hideBelow: "lg", text: dateOf },
  { key: "total", header: "Kwota", align: "right" },
  { key: "status", header: "Status" },
]);
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      ref="header"
      :title="title"
      :crumbs="crumbs"
    />

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
            :to="{ name: 'customers' }"
          >
            Wróć do listy klientów
          </RouterLink>
        </Button>
      </AlertDescription>
    </Alert>

    <Alert
      v-if="error"
      data-testid="customer-error"
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

    <Skeleton
      v-if="showSkeleton"
      data-testid="customer-loading"
      class="h-80 w-full"
    />

    <!-- From 1280 px two columns: the main one on the left, the side column of 320 px on the right. Below that one
         column with the side one above, which is the order of the source (and of Tab), as in the order details. -->
    <div
      v-if="customer"
      class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start"
    >
      <div
        data-testid="customer-side"
        class="flex min-w-0 flex-col gap-6 xl:col-start-2 xl:row-start-1"
      >
        <FormSection title="Kontakt">
          <dl
            data-testid="contact"
            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm"
          >
            <dt class="text-muted-foreground">
              Imię i nazwisko
            </dt>
            <dd class="min-w-0 break-words">
              {{ customer.contact.fullName }}
            </dd>
            <dt class="text-muted-foreground">
              E-mail
            </dt>
            <dd class="min-w-0">
              <a
                data-testid="contact-email"
                :href="`mailto:${customer.contact.email}`"
                class="rounded-sm break-all text-interface underline underline-offset-4"
              >{{ customer.contact.email }}</a>
            </dd>
            <dt class="text-muted-foreground">
              Telefon
            </dt>
            <dd class="min-w-0 break-words">
              <a
                data-testid="contact-phone"
                :href="`tel:${customer.contact.phone}`"
                class="rounded-sm text-interface underline underline-offset-4"
              >{{ customer.contact.phone }}</a>
            </dd>
          </dl>
        </FormSection>

        <FormSection title="Podsumowanie">
          <dl
            data-testid="summary"
            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm"
          >
            <dt class="text-muted-foreground">
              Zamówienia
            </dt>
            <dd
              data-testid="summary-orders"
              class="min-w-0 tabular-nums"
            >
              {{ customer.summary.orderCount }}
            </dd>
            <dt class="text-muted-foreground">
              Opłacone
            </dt>
            <dd
              data-testid="summary-paid"
              class="min-w-0 tabular-nums"
            >
              {{ customer.summary.paidCount }}
            </dd>
            <dt class="text-muted-foreground">
              Wartość opłaconych
            </dt>
            <dd
              data-testid="summary-value"
              class="min-w-0 tabular-nums"
            >
              {{ formatMoney(customer.summary.paidGross, customer.summary.currency) }}
            </dd>
            <dt class="text-muted-foreground">
              Pierwsze zamówienie
            </dt>
            <dd
              data-testid="summary-first"
              class="min-w-0"
            >
              {{ formatDateTime(customer.summary.firstOrderAt) }}
            </dd>
            <dt class="text-muted-foreground">
              Ostatnie zamówienie
            </dt>
            <dd
              data-testid="summary-last"
              class="min-w-0"
            >
              {{ formatDateTime(customer.summary.lastOrderAt) }}
            </dd>
          </dl>
        </FormSection>

        <FormSection title="Adresy">
          <p
            v-if="customer.addresses.length === 0"
            data-testid="no-addresses"
            class="text-sm text-muted-foreground"
          >
            Brak adresów dostawy.
          </p>
          <ul
            v-else
            class="flex flex-col gap-3"
          >
            <li
              v-for="(address, index) in customer.addresses"
              :key="index"
              data-testid="address"
            >
              <address class="text-sm not-italic">
                {{ address.street }}<br>
                {{ address.postalCode }} {{ address.city }}<br>
                {{ address.country }}
              </address>
            </li>
          </ul>
        </FormSection>
      </div>

      <div
        data-testid="customer-main"
        class="flex min-w-0 flex-col gap-6 xl:col-start-1 xl:row-start-1"
      >
        <section
          :aria-labelledby="ordersHeading"
          class="flex flex-col rounded-lg border border-separator bg-card text-card-foreground shadow-sm"
        >
          <h2
            :id="ordersHeading"
            class="px-5 pt-5 pb-4 text-base font-bold text-heading"
          >
            Zamówienia
          </h2>
          <ListToolbar
            :filtered="false"
            :per-page="orders.perPage.value"
            @update:per-page="orders.setPerPage"
          />

          <DataTable
            label="Zamówienia klienta"
            :cards="!tableLayout"
            :columns="columns"
            :rows="orders.items.value"
            :row-key="(order: CustomerOrderItem) => order.orderId"
            row-testid="order-row"
            error-testid="orders-error"
            :loading="orders.loading.value"
            :loaded="orders.loaded.value"
            :error="orders.error.value"
            :forbidden="orders.forbidden.value"
            :retry="orders.retry"
          >
            <template #cell-number="{ row }">
              <RouterLink
                data-testid="number"
                class="rounded-sm font-bold whitespace-nowrap text-heading hover:underline"
                :to="{ name: 'order-detail', params: { orderId: row.orderId } }"
              >
                {{ row.number }}
              </RouterLink>
            </template>
            <template #cell-placedAt="{ row }">
              <span data-testid="placed-at">{{ dateOf(row) }}</span>
            </template>
            <template #cell-total="{ row }">
              <span
                data-testid="total"
                class="whitespace-nowrap tabular-nums"
              >{{ formatMoney(row.totalGross, row.currency) }}</span>
            </template>
            <template #cell-status="{ row }">
              <Badge
                data-testid="status"
                :variant="statusLabel(row.status).variant"
              >
                {{ statusLabel(row.status).label }}
              </Badge>
            </template>

            <template #card="{ row }">
              <div class="flex flex-col gap-2">
                <div class="flex items-start justify-between gap-3">
                  <RouterLink
                    data-testid="number"
                    class="rounded-sm font-bold whitespace-nowrap text-heading hover:underline"
                    :to="{ name: 'order-detail', params: { orderId: row.orderId } }"
                  >
                    {{ row.number }}
                  </RouterLink>
                  <Badge
                    data-testid="status"
                    :variant="statusLabel(row.status).variant"
                  >
                    {{ statusLabel(row.status).label }}
                  </Badge>
                </div>
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                  <span
                    data-testid="total"
                    class="font-bold whitespace-nowrap tabular-nums"
                  >{{ formatMoney(row.totalGross, row.currency) }}</span>
                  <span
                    data-testid="placed-at"
                    class="text-xs text-muted-foreground"
                  >{{ dateOf(row) }}</span>
                </div>
              </div>
            </template>

            <template #empty>
              <ListEmpty
                :filtered="false"
                :later-page="orders.hasPrevious.value"
                :empty-text="EMPTY_MESSAGE"
              />
            </template>
          </DataTable>

          <div
            v-if="orders.loaded.value && (orders.items.value.length > 0 || orders.hasPrevious.value)"
            class="border-t border-separator px-4 py-3"
          >
            <ListPager
              :has-previous="orders.hasPrevious.value"
              :has-next="orders.hasNext.value"
              :page-number="orders.pageNumber.value"
              :range="orders.range.value"
              :total="orders.total.value"
              @previous="orders.goPrevious"
              @next="orders.goNext"
            />
          </div>
        </section>
      </div>
    </div>
  </div>
</template>
