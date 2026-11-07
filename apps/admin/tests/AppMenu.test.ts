import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import AppMenu from "@/layout/AppMenu.vue";

describe("AppMenu", () => {
  it("links the dashboard, the products, the price list and the stock list", async () => {
    const empty = { template: "<div />" };
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: "/", name: "dashboard", component: empty },
        { path: "/products", name: "products", component: empty },
        { path: "/prices", name: "prices", component: empty },
        { path: "/inventory", name: "inventory", component: empty },
      ],
    });
    await router.push("/prices");

    const wrapper = mount(AppMenu, { global: { plugins: [router] } });

    const links = wrapper.findAll("a").map((link) => [link.text(), link.attributes("href")]);
    expect(links).toEqual([
      ["Dashboard", "/"],
      ["Produkty", "/products"],
      ["Ceny", "/prices"],
      ["Stany magazynowe", "/inventory"],
    ]);
    expect(wrapper.get("a[href='/prices']").classes()).toContain("menu-item--active");
    expect(wrapper.get("a[href='/inventory']").classes()).not.toContain("menu-item--active");
  });
});
