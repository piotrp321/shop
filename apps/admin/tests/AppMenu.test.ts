import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import AppMenu from "@/layout/AppMenu.vue";
import { type StaffMe, useAuthStore } from "@/stores/auth";

type Role = StaffMe["roles"][number];

const empty = { template: "<div />" };

// The shape of the router of the app: the lists, the new objects and the pages of objects share a path.
function createTestRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/products", name: "products", component: empty },
      { path: "/products/new", name: "product-new", component: empty },
      { path: "/products/:id", name: "product", component: empty },
      { path: "/prices", name: "prices", component: empty },
      {
        path: "/promotions",
        children: [
          { path: "", name: "promotions", component: empty },
          { path: "new", name: "promotion-new", component: empty },
          { path: ":id", name: "promotion", component: empty },
        ],
      },
      {
        path: "/discount-codes",
        children: [
          { path: "", name: "discount-codes", component: empty },
          { path: "new", name: "discount-code-new", component: empty },
          { path: ":id", name: "discount-code", component: empty },
        ],
      },
      { path: "/inventory", name: "inventory", component: empty },
      {
        path: "/orders",
        children: [
          { path: "", name: "orders", component: empty },
          { path: ":orderId", name: "order-detail", component: empty },
        ],
      },
    ],
  });
}

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
});

async function mountMenu(path: string, roles: Role[] | null) {
  const router = createTestRouter();
  await router.push(path);
  const pinia = createPinia();
  setActivePinia(pinia);
  if (roles !== null) {
    useAuthStore().staff = { id: "u1", username: "anna", roles };
  }
  wrapper = mount(AppMenu, { global: { plugins: [pinia, router] }, attachTo: document.body });
  return { wrapper, router };
}

/** Every link of the menu, also those in a collapsed group, as [label, href]. */
function links(menu: VueWrapper) {
  return menu.findAll("a").map((link) => [link.text(), link.attributes("href")]);
}

function groupButtons(menu: VueWrapper) {
  return menu.findAll("button").map((button) => [button.text(), button.attributes("aria-expanded")]);
}

function current(menu: VueWrapper) {
  return menu.findAll("[aria-current]").map((item) => `${item.text()}|${item.attributes("aria-current")}`);
}

const ADMIN_LINKS = [
  ["Pulpit", "/"],
  ["Lista produktów", "/products"],
  ["Dodaj produkt", "/products/new"],
  ["Ceny", "/prices"],
  ["Stany magazynowe", "/inventory"],
  ["Lista zamówień", "/orders"],
  ["Zwroty do wykonania", "/orders?refund=pending"],
  ["Promocje", "/promotions"],
  ["Kody rabatowe", "/discount-codes"],
];

describe("AppMenu: structure and roles", () => {
  it("is the navigation „Menu główne” with the sections Główne, Sklep and Marketing", async () => {
    const { wrapper: menu } = await mountMenu("/", ["admin"]);

    expect(menu.get("nav").attributes("aria-label")).toBe("Menu główne");
    expect(menu.findAll("[data-testid=menu-section]").map((section) => section.text())).toEqual(["Główne", "Sklep", "Marketing"]);
  });

  it("links everything for admin, with the groups Katalog, Produkty and Zamówienia", async () => {
    const { wrapper: menu } = await mountMenu("/", ["admin"]);

    expect(links(menu)).toEqual(ADMIN_LINKS);
    expect(groupButtons(menu).map(([label]) => label)).toEqual(["Katalog", "Produkty", "Zamówienia"]);
  });

  it("does not offer Stan systemu: the screen does not exist yet", async () => {
    const { wrapper: menu } = await mountMenu("/", ["admin"]);

    expect(menu.text()).not.toContain("Stan systemu");
  });

  it("links the products, the new product and no orders for marketing", async () => {
    const { wrapper: menu } = await mountMenu("/", ["marketing"]);

    expect(links(menu)).toEqual(ADMIN_LINKS.filter(([label]) => label !== "Lista zamówień" && label !== "Zwroty do wykonania"));
    expect(groupButtons(menu).map(([label]) => label)).toEqual(["Katalog", "Produkty"]);
  });

  it("links the orders and the refunds to do but not the new product for support", async () => {
    const { wrapper: menu } = await mountMenu("/", ["support"]);

    expect(links(menu)).toEqual(ADMIN_LINKS.filter(([label]) => label !== "Dodaj produkt"));
  });

  it("links the list of the orders but neither the refunds to do nor the new product for warehouse", async () => {
    const { wrapper: menu } = await mountMenu("/", ["warehouse"]);

    expect(links(menu)).toEqual(ADMIN_LINKS.filter(([label]) => label !== "Dodaj produkt" && label !== "Zwroty do wykonania"));
  });

  it("links the refunds to do for a member with the role among others", async () => {
    const { wrapper: menu } = await mountMenu("/", ["marketing", "support"]);

    expect(links(menu)).toContainEqual(["Zwroty do wykonania", "/orders?refund=pending"]);
    expect(links(menu)).toContainEqual(["Dodaj produkt", "/products/new"]);
  });

  it("hides what needs a role until the staff member is known, and keeps what every staff member may read", async () => {
    const { wrapper: menu } = await mountMenu("/", null);

    expect(links(menu)).toEqual([
      ["Pulpit", "/"],
      ["Lista produktów", "/products"],
      ["Ceny", "/prices"],
      ["Stany magazynowe", "/inventory"],
      ["Promocje", "/promotions"],
      ["Kody rabatowe", "/discount-codes"],
    ]);
    expect(groupButtons(menu).map(([label]) => label)).toEqual(["Katalog", "Produkty"]);
  });

  it("shows the roles' items once the staff member loads", async () => {
    const { wrapper: menu } = await mountMenu("/", null);

    useAuthStore().staff = { id: "u1", username: "anna", roles: ["support"] };
    await flushPromises();

    expect(links(menu)).toContainEqual(["Zwroty do wykonania", "/orders?refund=pending"]);
  });
});

describe("AppMenu: the current item", () => {
  it.each([
    ["/", "Pulpit"],
    ["/products", "Lista produktów"],
    ["/products/0192f0c4", "Lista produktów"],
    ["/products/new", "Dodaj produkt"],
    ["/prices", "Ceny"],
    ["/inventory", "Stany magazynowe"],
    ["/promotions", "Promocje"],
    ["/promotions/new", "Promocje"],
    ["/promotions/0192f0c4", "Promocje"],
    ["/discount-codes", "Kody rabatowe"],
    ["/discount-codes/new", "Kody rabatowe"],
    ["/discount-codes/0192f0c4", "Kody rabatowe"],
    ["/orders", "Lista zamówień"],
    ["/orders?status=paid", "Lista zamówień"],
    ["/orders/0192f0c4", "Lista zamówień"],
    ["/orders?refund=pending", "Zwroty do wykonania"],
  ])("marks only %s as current: %s", async (path, label) => {
    const { wrapper: menu } = await mountMenu(path, ["admin"]);

    expect(current(menu)).toEqual([`${label}|page`]);
  });

  it("does not mark the dashboard as current anywhere but on /", async () => {
    for (const path of ["/products", "/orders", "/prices"]) {
      const { wrapper: menu } = await mountMenu(path, ["admin"]);
      expect(current(menu).filter((item) => item.startsWith("Pulpit"))).toEqual([]);
      menu.unmount();
    }
  });

  it("marks the list of the orders for warehouse, who has no item for the refunds, on /orders?refund=pending", async () => {
    const { wrapper: menu } = await mountMenu("/orders?refund=pending", ["warehouse"]);

    expect(current(menu)).toEqual(["Lista zamówień|page"]);
  });

  it("moves the mark when the route changes, also between the list and the refunds of the same path", async () => {
    const { wrapper: menu, router } = await mountMenu("/orders", ["admin"]);

    await router.push("/orders?refund=pending");
    expect(current(menu)).toEqual(["Zwroty do wykonania|page"]);

    await router.push("/orders");
    expect(current(menu)).toEqual(["Lista zamówień|page"]);
  });
});

describe("AppMenu: groups", () => {
  it("has every group collapsed on the dashboard and hides the links of a collapsed group", async () => {
    const { wrapper: menu } = await mountMenu("/", ["admin"]);

    expect(groupButtons(menu)).toEqual([
      ["Katalog", "false"],
      ["Produkty", "false"],
      ["Zamówienia", "false"],
    ]);
    expect(menu.get("a[href='/prices']").isVisible()).toBe(false);
    expect(menu.get("a[href='/']").isVisible()).toBe(true);
    expect(menu.get("a[href='/promotions']").isVisible()).toBe(true);
  });

  it.each([
    ["/products/0192f0c4", ["true", "true", "false"]],
    ["/products/new", ["true", "true", "false"]],
    ["/prices", ["true", "false", "false"]],
    ["/inventory", ["true", "false", "false"]],
    ["/orders/0192f0c4", ["false", "false", "true"]],
    ["/orders?refund=pending", ["false", "false", "true"]],
    ["/promotions", ["false", "false", "false"]],
  ])("expands the groups of the current route on entering %s", async (path, expected) => {
    const { wrapper: menu } = await mountMenu(path, ["admin"]);

    expect(groupButtons(menu).map(([, expanded]) => expanded)).toEqual(expected);
  });

  it("shows the current item of an expanded group", async () => {
    const { wrapper: menu } = await mountMenu("/orders?refund=pending", ["admin"]);

    expect(menu.get("a[href='/orders?refund=pending']").isVisible()).toBe(true);
    expect(menu.get("a[href='/orders']").isVisible()).toBe(true);
  });

  it("toggles a group by its button, which controls the region of its items", async () => {
    const { wrapper: menu } = await mountMenu("/", ["admin"]);
    const button = menu.findAll("button").find((candidate) => candidate.text() === "Katalog");
    expect(button).toBeDefined();
    const controlled = menu.get(`#${button?.attributes("aria-controls")}`);
    expect(controlled.isVisible()).toBe(false);

    await button?.trigger("click");
    expect(button?.attributes("aria-expanded")).toBe("true");
    expect(controlled.isVisible()).toBe(true);
    expect(menu.get("a[href='/prices']").isVisible()).toBe(true);

    await button?.trigger("click");
    expect(button?.attributes("aria-expanded")).toBe("false");
    expect(controlled.isVisible()).toBe(false);
  });

  it("opens the group of a route the staff member navigates to", async () => {
    const { wrapper: menu, router } = await mountMenu("/", ["admin"]);

    await router.push("/orders");

    expect(groupButtons(menu).find(([label]) => label === "Zamówienia")?.[1]).toBe("true");
    expect(groupButtons(menu).find(([label]) => label === "Katalog")?.[1]).toBe("false");
  });

  it("does not collapse a group on its own when the staff member opened it and moves on inside it", async () => {
    const { wrapper: menu, router } = await mountMenu("/prices", ["admin"]);

    await router.push("/inventory");

    expect(groupButtons(menu).find(([label]) => label === "Katalog")?.[1]).toBe("true");
  });

  it("keeps the focus on a group button after toggling it", async () => {
    const { wrapper: menu } = await mountMenu("/", ["admin"]);
    const button = menu.get("button");
    (button.element as HTMLButtonElement).focus();

    await button.trigger("click");

    expect(document.activeElement).toBe(button.element);
  });
});

describe("AppMenu: choosing an item", () => {
  it("navigates by the link and tells the parent, which closes the drawer", async () => {
    const { wrapper: menu, router } = await mountMenu("/", ["admin"]);

    await menu.get("a[href='/promotions']").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("promotions");
    expect(menu.emitted("navigate")).toHaveLength(1);
  });

  it("tells the parent also when the item is the current one, so the drawer closes", async () => {
    const { wrapper: menu } = await mountMenu("/promotions", ["admin"]);

    await menu.get("a[href='/promotions']").trigger("click");

    expect(menu.emitted("navigate")).toHaveLength(1);
  });

  it("opens the list of the orders filtered by the refunds from the menu item", async () => {
    const { wrapper: menu, router } = await mountMenu("/orders", ["admin"]);

    await menu.get("a[href='/orders?refund=pending']").trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.fullPath).toBe("/orders?refund=pending");
  });
});
