import { CreditCard, PackageCheck, Percent, Ticket, Undo2, Warehouse } from "lucide-vue-next";
import type { Component } from "vue";
import type { RouteLocationRaw } from "vue-router";
import type { StaffMe } from "@/stores/auth";
import type { KpiTone } from "./KpiTile.vue";

type Role = StaffMe["roles"][number];

export interface TodoItem {
  id: string;
  label: string;
  description: string;
  to: RouteLocationRaw;
  icon: Component;
  tone: KpiTone;
  /** The roles that have the row; the card only hides it, the screen it leads to is guarded by the API. */
  roles: Role[];
}

/** The rows of „Do obsługi” (docs/features/admin-design/spec.md, „Pulpit i Stan systemu”): links without counts. */
export const TODO: TodoItem[] = [
  {
    id: "paid",
    label: "Opłacone do wysłania",
    description: "Zamówienia opłacone, które czekają na wysyłkę.",
    to: { name: "orders", query: { status: "paid" } },
    icon: PackageCheck,
    tone: "blue",
    roles: ["admin", "support", "warehouse"],
  },
  {
    id: "new",
    label: "Czekają na płatność",
    description: "Zamówienia złożone, ale jeszcze nieopłacone.",
    to: { name: "orders", query: { status: "new" } },
    icon: CreditCard,
    tone: "amber",
    roles: ["admin", "support"],
  },
  {
    id: "refunds",
    label: "Zwroty do wykonania",
    description: "Zwroty pieniędzy, które trzeba jeszcze wykonać.",
    to: { name: "orders", query: { refund: "pending" } },
    icon: Undo2,
    tone: "violet",
    roles: ["admin", "support"],
  },
  {
    id: "inventory",
    label: "Stany magazynowe",
    description: "Przyjęcia i korekty stanów magazynowych.",
    to: { name: "inventory" },
    icon: Warehouse,
    tone: "teal",
    roles: ["admin", "warehouse"],
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

/** The rows for a staff member, in the order of the list; a member with several roles sees each row once. */
export function todoFor(staff: StaffMe | null): TodoItem[] {
  return staff === null ? [] : TODO.filter((item) => item.roles.some((role) => staff.roles.includes(role)));
}
