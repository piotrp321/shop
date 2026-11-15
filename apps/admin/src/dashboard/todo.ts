import { AlertTriangle, CreditCard, ImageOff, PackageCheck, PackageMinus, PackageSearch, Percent, Ticket, Undo2 } from "lucide-vue-next";
import type { Component } from "vue";
import type { RouteLocationRaw } from "vue-router";
import type { StaffMe } from "@/stores/auth";
import type { KpiTone } from "./KpiTile.vue";
import type { Dashboard } from "./useDashboard";

type Role = StaffMe["roles"][number];
type Queues = Dashboard["queues"];
type Count = NonNullable<Queues[keyof Queues]>;

export interface TodoItem {
  id: string;
  label: string;
  description: string;
  to: RouteLocationRaw;
  icon: Component;
  tone: KpiTone;
  /** The roles that have the row; the card only hides it, the screen it leads to is guarded by the API. */
  roles: Role[];
  /** The queue of the dashboard whose count the row shows; a row without one is a plain link. */
  queue?: keyof Queues;
}

/** A row with the count of its queue as text (`null` while the dashboard is not loaded, and for a row without a queue). */
export interface TodoRow extends TodoItem {
  count: string | null;
}

/**
 * The rows of „Do obsługi” (docs/features/admin-design/spec.md, „Pulpit i Stan systemu”): each queue links to its
 * filtered list and shows its count.
 */
export const TODO: TodoItem[] = [
  {
    id: "paid",
    label: "Opłacone do wysłania",
    description: "Zamówienia opłacone, które czekają na wysyłkę.",
    to: { name: "orders", query: { status: "paid" } },
    icon: PackageCheck,
    tone: "blue",
    roles: ["admin", "support", "warehouse"],
    queue: "paidToShip",
  },
  {
    id: "new",
    label: "Czekają na płatność",
    description: "Zamówienia złożone, ale jeszcze nieopłacone.",
    to: { name: "orders", query: { status: "new" } },
    icon: CreditCard,
    tone: "amber",
    roles: ["admin", "support"],
    queue: "awaitingPayment",
  },
  {
    id: "refunds",
    label: "Zwroty do wykonania",
    description: "Zwroty pieniędzy, które trzeba jeszcze wykonać.",
    to: { name: "orders", query: { refund: "pending" } },
    icon: Undo2,
    tone: "violet",
    roles: ["admin", "support"],
    queue: "pendingRefunds",
  },
  {
    id: "overdue-refunds",
    label: "Zwroty po terminie",
    description: "Zwroty pieniędzy, których termin już minął.",
    to: { name: "orders", query: { refund: "overdue" } },
    icon: AlertTriangle,
    tone: "amber",
    roles: ["admin", "support"],
    queue: "overdueRefunds",
  },
  {
    id: "inventory",
    label: "Stany magazynowe",
    description: "Warianty, których nie ma na stanie.",
    to: { name: "inventory", query: { level: "out_of_stock" } },
    icon: PackageMinus,
    tone: "teal",
    roles: ["admin", "warehouse"],
    queue: "outOfStockVariants",
  },
  {
    id: "low-stock",
    label: "Niski stan",
    description: "Warianty z kilkoma ostatnimi sztukami.",
    to: { name: "inventory", query: { level: "low_stock" } },
    icon: PackageSearch,
    tone: "amber",
    roles: ["admin", "warehouse"],
    queue: "lowStockVariants",
  },
  {
    id: "without-images",
    label: "Produkty bez zdjęć",
    description: "Produkty, którym trzeba dodać zdjęcia.",
    to: { name: "products", query: { images: "none" } },
    icon: ImageOff,
    tone: "violet",
    roles: ["admin", "marketing"],
    queue: "productsWithoutImages",
  },
  {
    id: "promotions",
    label: "Aktywne promocje",
    description: "Promocje, które trwają teraz.",
    to: { name: "promotions", query: { status: "active" } },
    icon: Percent,
    tone: "green",
    roles: ["admin", "marketing"],
  },
  {
    id: "discount-codes",
    label: "Kody rabatowe",
    description: "Kody rabatowe, które można teraz wykorzystać.",
    to: { name: "discount-codes", query: { status: "active" } },
    icon: Ticket,
    tone: "blue",
    roles: ["admin", "marketing"],
  },
];

/** A count of a queue as text: the number, and „1000+” when the API stopped counting at its limit. */
export function formatCount(count: Count): string {
  return count.capped ? `${count.value}+` : String(count.value);
}

/**
 * The rows for a staff member, in the order of the list; a member with several roles sees each row once. With the
 * dashboard loaded a queue the API gave null (the member has no access to it) has no row and the others carry their
 * count; before that (loading, or the request failed) the rows of the roles show without counts.
 */
export function todoFor(staff: StaffMe | null, queues: Queues | null = null): TodoRow[] {
  if (staff === null) {
    return [];
  }
  return TODO.filter((item) => item.roles.some((role) => staff.roles.includes(role)))
    .filter((item) => queues === null || item.queue === undefined || queues[item.queue] !== null)
    .map((item) => {
      const count = queues === null || item.queue === undefined ? null : queues[item.queue];
      return { ...item, count: count === null ? null : formatCount(count) };
    });
}
