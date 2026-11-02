import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import AppMenu from "@/layout/AppMenu.vue";

describe("AppMenu", () => {
  it("links the dashboard and the price list", async () => {
    const empty = { template: "<div />" };
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: "/", name: "dashboard", component: empty },
        { path: "/prices", name: "prices", component: empty },
      ],
    });
    await router.push("/prices");

    const wrapper = mount(AppMenu, { global: { plugins: [router] } });

    const links = wrapper.findAll("a").map((link) => [link.text(), link.attributes("href")]);
    expect(links).toEqual([
      ["Dashboard", "/"],
      ["Ceny", "/prices"],
    ]);
    expect(wrapper.get("a[href='/prices']").classes()).toContain("menu-item--active");
  });
});
