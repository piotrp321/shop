import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import AppMenu from "@/layout/AppMenu.vue";
import { type StaffMe, useAuthStore } from "@/stores/auth";

type Role = StaffMe["roles"][number];

const empty = { template: "<div />" };

async function mountMenu(path: string, roles: Role[] | null) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/products", name: "products", component: empty },
      { path: "/prices", name: "prices", component: empty },
      // The shape of the router of the app: the list, the new promotion and a promotion are children of one `promotions` path.
      {
        path: "/promotions",
        children: [
          { path: "", name: "promotions", component: empty },
          { path: "new", name: "promotion-new", component: empty },
          { path: ":id", name: "promotion", component: empty },
        ],
      },
      // The shape of the router of the app: the list, the new code and a code are children of one `discount-codes` path.
      {
        path: "/discount-codes",
        children: [
          { path: "", name: "discount-codes", component: empty },
          { path: "new", name: "discount-code-new", component: empty },
          { path: ":id", name: "discount-code", component: empty },
        ],
      },
      { path: "/inventory", name: "inventory", component: empty },
      // The shape of the router of the app: the list and the detail are children of one `orders` path.
      {
        path: "/orders",
        children: [
          { path: "", name: "orders", component: empty },
          { path: ":orderId", name: "order-detail", component: empty },
        ],
      },
    ],
  });
  await router.push(path);
  const pinia = createPinia();
  setActivePinia(pinia);
  if (roles !== null) {
    useAuthStore().staff = { id: "u1", username: "anna", roles };
  }
  return mount(AppMenu, { global: { plugins: [pinia, router] } });
}

function links(wrapper: Awaited<ReturnType<typeof mountMenu>>) {
  return wrapper.findAll("a").map((link) => [link.text(), link.attributes("href")]);
}

describe("AppMenu", () => {
  it("links the dashboard, the products, the price list, the promotions, the discount codes and the stock list", async () => {
    const wrapper = await mountMenu("/prices", ["marketing"]);

    expect(links(wrapper)).toEqual([
      ["Dashboard", "/"],
      ["Produkty", "/products"],
      ["Ceny", "/prices"],
      ["Promocje", "/promotions"],
      ["Kody rabatowe", "/discount-codes"],
      ["Stany magazynowe", "/inventory"],
    ]);
    expect(wrapper.get("a[href='/prices']").classes()).toContain("menu-item--active");
    expect(wrapper.get("a[href='/inventory']").classes()).not.toContain("menu-item--active");
  });

  it.each(["admin", "support", "warehouse"] as const)("links the orders for %s", async (role) => {
    const wrapper = await mountMenu("/", [role]);

    expect(links(wrapper)).toEqual([
      ["Dashboard", "/"],
      ["Produkty", "/products"],
      ["Ceny", "/prices"],
      ["Promocje", "/promotions"],
      ["Kody rabatowe", "/discount-codes"],
      ["Stany magazynowe", "/inventory"],
      ["Zamówienia", "/orders"],
    ]);
  });

  it("links the orders for a member with any of the roles among others", async () => {
    const wrapper = await mountMenu("/", ["marketing", "support"]);

    expect(links(wrapper)).toContainEqual(["Zamówienia", "/orders"]);
  });

  it("does not link the orders for marketing", async () => {
    const wrapper = await mountMenu("/", ["marketing"]);

    expect(links(wrapper).map(([label]) => label)).not.toContain("Zamówienia");
  });

  it("does not link the orders before the staff member is known", async () => {
    const wrapper = await mountMenu("/", null);

    expect(links(wrapper).map(([label]) => label)).not.toContain("Zamówienia");
  });

  it.each(["admin", "marketing", "support", "warehouse"] as const)("links the promotions for %s", async (role) => {
    const wrapper = await mountMenu("/", [role]);

    expect(links(wrapper)).toContainEqual(["Promocje", "/promotions"]);
  });

  it("links the promotions before the staff member is known: every staff member may read them", async () => {
    const wrapper = await mountMenu("/", null);

    expect(links(wrapper)).toContainEqual(["Promocje", "/promotions"]);
  });

  it.each(["admin", "marketing", "support", "warehouse"] as const)("links the discount codes for %s", async (role) => {
    const wrapper = await mountMenu("/", [role]);

    expect(links(wrapper)).toContainEqual(["Kody rabatowe", "/discount-codes"]);
  });

  it("links the discount codes before the staff member is known: every staff member may read them", async () => {
    const wrapper = await mountMenu("/", null);

    expect(links(wrapper)).toContainEqual(["Kody rabatowe", "/discount-codes"]);
  });

  it.each(["/discount-codes", "/discount-codes/new", "/discount-codes/0192f0c4"])("keeps the discount codes marked active on %s, not the promotions", async (path) => {
    const wrapper = await mountMenu(path, ["marketing"]);

    expect(wrapper.get("a[href='/discount-codes']").classes()).toContain("menu-item--active");
    expect(wrapper.get("a[href='/promotions']").classes()).not.toContain("menu-item--active");
  });

  it.each(["/promotions", "/promotions/new", "/promotions/0192f0c4"])("keeps the promotions marked active on %s", async (path) => {
    const wrapper = await mountMenu(path, ["marketing"]);

    expect(wrapper.get("a[href='/promotions']").classes()).toContain("menu-item--active");
    expect(wrapper.get("a[href='/prices']").classes()).not.toContain("menu-item--active");
  });

  it("keeps the orders marked active on the detail of an order", async () => {
    const wrapper = await mountMenu("/orders/0192f0c4", ["support"]);

    expect(wrapper.get("a[href='/orders']").classes()).toContain("menu-item--active");
  });
});
