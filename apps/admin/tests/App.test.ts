import { mount } from "@vue/test-utils";
import { createPinia } from "pinia";
import PrimeVue from "primevue/config";
import { describe, expect, it } from "vitest";
import App from "@/App.vue";
import { createAppRouter } from "@/router";
import { primeVueOptions } from "@/primevue";

describe("App", () => {
  it("mounts with PrimeVue, Pinia and the router and renders the home view", async () => {
    const router = createAppRouter();
    await router.push("/");
    await router.isReady();

    const wrapper = mount(App, { global: { plugins: [createPinia(), router, [PrimeVue, primeVueOptions]] } });

    expect(wrapper.text()).toContain("Panel administracyjny");
    expect(wrapper.find("button.p-button").exists()).toBe(true);
  });
});
