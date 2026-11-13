import { Activity, LayoutDashboard, Package, Percent, ShoppingCart, Ticket } from "lucide-vue-next";
import type { Component } from "vue";
import type { RouteLocationNormalizedLoaded, RouteLocationRaw } from "vue-router";
import { canViewSystem } from "@/dashboard/permissions";
import { canCompleteRefunds, canViewOrders } from "@/orders/permissions";
import { canManageProducts } from "@/products/permissions";
import type { StaffMe } from "@/stores/auth";

type Staff = StaffMe | null;
type CurrentRoute = Pick<RouteLocationNormalizedLoaded, "name" | "query">;

interface Visibility {
  /** Whether the staff member sees the entry; without it everyone does. Only hides: the API checks the access. */
  visible?: (staff: Staff) => boolean;
}

export interface MenuLeaf extends Visibility {
  kind: "leaf";
  id: string;
  label: string;
  to: RouteLocationRaw;
  icon?: Component;
  /** Whether the route is this item (forms and details belong to the item of their list). */
  matches: (route: CurrentRoute) => boolean;
  /** Of several matching items the one with the highest priority is the current one (default 0). */
  priority?: number;
}

export interface MenuGroup extends Visibility {
  kind: "group";
  id: string;
  label: string;
  icon?: Component;
  children: MenuEntry[];
}

export type MenuEntry = MenuLeaf | MenuGroup;

export interface MenuSection {
  id: string;
  title: string;
  entries: MenuEntry[];
}

const named =
  (...names: string[]) =>
  (route: CurrentRoute) =>
    typeof route.name === "string" && names.includes(route.name);

const refundsToDo = (route: CurrentRoute) => route.name === "orders" && route.query.refund === "pending";

/** The menu of the panel (docs/features/admin-design/spec.md, „Powłoka”). */
export const MENU: MenuSection[] = [
  {
    id: "main",
    title: "Główne",
    entries: [{ kind: "leaf", id: "dashboard", label: "Pulpit", to: { name: "dashboard" }, icon: LayoutDashboard, matches: named("dashboard") }],
  },
  {
    id: "shop",
    title: "Sklep",
    entries: [
      {
        kind: "group",
        id: "catalog",
        label: "Katalog",
        icon: Package,
        children: [
          {
            kind: "group",
            id: "products",
            label: "Produkty",
            children: [
              { kind: "leaf", id: "product-list", label: "Lista produktów", to: { name: "products" }, matches: named("products", "product") },
              { kind: "leaf", id: "product-new", label: "Dodaj produkt", to: { name: "product-new" }, matches: named("product-new"), visible: canManageProducts },
            ],
          },
          { kind: "leaf", id: "prices", label: "Ceny", to: { name: "prices" }, matches: named("prices") },
          { kind: "leaf", id: "inventory", label: "Stany magazynowe", to: { name: "inventory" }, matches: named("inventory") },
        ],
      },
      {
        kind: "group",
        id: "orders",
        label: "Zamówienia",
        icon: ShoppingCart,
        visible: canViewOrders,
        children: [
          { kind: "leaf", id: "order-list", label: "Lista zamówień", to: { name: "orders" }, matches: named("orders", "order-detail") },
          {
            kind: "leaf",
            id: "order-refunds",
            label: "Zwroty do wykonania",
            to: { name: "orders", query: { refund: "pending" } },
            matches: refundsToDo,
            priority: 1,
            visible: canCompleteRefunds,
          },
        ],
      },
    ],
  },
  {
    id: "marketing",
    title: "Marketing",
    entries: [
      { kind: "leaf", id: "promotions", label: "Promocje", to: { name: "promotions" }, icon: Percent, matches: named("promotions", "promotion-new", "promotion") },
      {
        kind: "leaf",
        id: "discount-codes",
        label: "Kody rabatowe",
        to: { name: "discount-codes" },
        icon: Ticket,
        matches: named("discount-codes", "discount-code-new", "discount-code"),
      },
    ],
  },
  {
    id: "system",
    title: "System",
    entries: [{ kind: "leaf", id: "system", label: "Stan systemu", to: { name: "system" }, icon: Activity, matches: named("system"), visible: canViewSystem }],
  },
];

function visibleEntries(entries: MenuEntry[], staff: Staff): MenuEntry[] {
  return entries.flatMap((entry): MenuEntry[] => {
    if (entry.visible !== undefined && !entry.visible(staff)) {
      return [];
    }
    if (entry.kind === "leaf") {
      return [entry];
    }
    const children = visibleEntries(entry.children, staff);
    return children.length === 0 ? [] : [{ ...entry, children }];
  });
}

/** The menu for a staff member (null until `/me` is loaded: only what every staff member may read). */
export function menuFor(staff: Staff): MenuSection[] {
  return MENU.map((section) => ({ ...section, entries: visibleEntries(section.entries, staff) })).filter((section) => section.entries.length > 0);
}

/** The leaves of the menu in order, each with the ids of the groups it sits in (outermost first). */
export function leavesOf(sections: MenuSection[]): { leaf: MenuLeaf; groups: string[] }[] {
  const found: { leaf: MenuLeaf; groups: string[] }[] = [];
  const walk = (entries: MenuEntry[], groups: string[]) => {
    for (const entry of entries) {
      if (entry.kind === "leaf") {
        found.push({ leaf: entry, groups });
      } else {
        walk(entry.children, [...groups, entry.id]);
      }
    }
  };
  for (const section of sections) {
    walk(section.entries, []);
  }
  return found;
}

/** The current item: of the matching ones the highest priority, the first on a tie; null when none matches. */
export function currentLeaf(sections: MenuSection[], route: CurrentRoute): { leaf: MenuLeaf; groups: string[] } | null {
  let best: { leaf: MenuLeaf; groups: string[] } | null = null;
  for (const candidate of leavesOf(sections)) {
    if (candidate.leaf.matches(route) && (best === null || (candidate.leaf.priority ?? 0) > (best.leaf.priority ?? 0))) {
      best = candidate;
    }
  }
  return best;
}
